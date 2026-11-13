"""Validación OpenAPI 3.1 y contraste con rutas/respuestas reales; solo desarrollo."""

import argparse
import json
import warnings
from importlib.metadata import version
from pathlib import Path

import yaml
from openapi_schema_validator import OAS31Validator
from openapi_spec_validator import OpenAPIV31SpecValidator
from referencing import Registry, Resource
from referencing.jsonschema import DRAFT202012


class UniqueKeyLoader(yaml.SafeLoader):
    def construct_mapping(self, node, deep=False):
        keys = [self.construct_object(key, deep=deep) for key, _ in node.value]
        if len(keys) != len(set(keys)):
            raise ValueError(f"Claves YAML duplicadas en línea {node.start_mark.line + 1}")
        return super().construct_mapping(node, deep=deep)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("spec", type=Path)
    parser.add_argument("--routes", type=Path, required=True)
    parser.add_argument("--responses", type=Path)
    parser.add_argument("--report", type=Path, required=True)
    args = parser.parse_args()
    errors = []
    response_count = 0
    covered = set()
    with warnings.catch_warnings(record=True) as recorded:
        warnings.simplefilter("always")
        spec = yaml.load(args.spec.read_text(encoding="utf-8-sig"), Loader=UniqueKeyLoader)
        if spec.get("openapi") != "3.1.0":
            errors.append("Se esperaba la versión contractual OpenAPI 3.1.0.")
        for error in OpenAPIV31SpecValidator(spec, base_uri=args.spec.resolve().as_uri()).iter_errors():
            errors.append(f"OpenAPI {list(error.absolute_path)}: {error.message}")
        documented = {(method, path) for path, item in spec.get("paths", {}).items()
                      for method in item if method in {"get", "post", "put", "patch", "delete", "head", "options"}}
        routes = json.loads(args.routes.read_text(encoding="utf-8-sig"))
        actual = {(route["method"], route["path"]) for route in routes}
        if actual != documented:
            errors.append(f"Rutas sin documentar: {sorted(actual - documented)}; ficticias: {sorted(documented - actual)}")
        for route in routes:
            if route["path"].startswith("/catalog/"):
                security = spec["paths"][route["path"]][route["method"]].get("security", spec.get("security", []))
                if not any("BearerAuth" in rule for rule in security) or not {"auth:sanctum", "auth.access.api"}.issubset(route["middleware"]):
                    errors.append(f"Autenticación incoherente en {route['method']} {route['path']}")
        uri = args.spec.resolve().as_uri()
        registry = Registry().with_resource(uri, Resource.from_contents(spec, default_specification=DRAFT202012))
        if args.responses:
            samples = json.loads(args.responses.read_text(encoding="utf-8-sig"))
            for sample in samples:
                method, path, status = sample["method"], sample["path"], str(sample["status"])
                pointer = "/paths/" + path.replace("~", "~0").replace("/", "~1") + "/" + method + "/responses/" + status
                try:
                    response = registry.resolver(uri).lookup("#" + pointer).contents
                    while "$ref" in response:
                        response = registry.resolver(uri).lookup(response["$ref"]).contents
                    schema = response["content"]["application/json"]["schema"]
                    validator = OAS31Validator(schema, registry=registry, _resolver=registry.resolver(uri), format_checker=OAS31Validator.FORMAT_CHECKER)
                    for error in validator.iter_errors(sample["body"]):
                        errors.append(f"Respuesta {method} {path} {status} {list(error.absolute_path)}: {error.message}")
                    response_count += 1
                    covered.add((method, path))
                except (KeyError, ValueError) as error:
                    errors.append(f"Contrato ausente en respuesta {method} {path} {status}: {error}")
            missing_samples = {operation for operation in actual if operation[1].startswith('/catalog/')} - covered
            if missing_samples:
                errors.append(f"Operaciones de Catalog sin respuesta real contrastada: {sorted(missing_samples)}")
    report = {"tool": "openapi-spec-validator", "version": version("openapi-spec-validator"),
              "response_validator": "openapi-schema-validator", "response_validator_version": version("openapi-schema-validator"),
              "file": args.spec.as_posix(), "openapi": spec.get("openapi"), "operations": len(actual),
              "catalog_operations": sum(path.startswith("/catalog/") for _, path in actual),
              "responses_validated": response_count, "operations_with_response_samples": len(covered),
              "errors": errors, "error_count": len(errors), "warnings": [str(item.message) for item in recorded],
              "warning_count": len(recorded), "result": "PASS" if not errors else "FAIL"}
    args.report.parent.mkdir(parents=True, exist_ok=True)
    args.report.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(report, ensure_ascii=True))
    return int(bool(errors))


if __name__ == "__main__":
    raise SystemExit(main())
