#!/usr/bin/env python3
"""Validate OpenAPI structure and both accepted and rejected consumer examples."""
import json
from pathlib import Path
from jsonschema import Draft202012Validator, FormatChecker
from openapi_spec_validator import validate_spec

root = Path(__file__).resolve().parents[1] / 'docs/contracts'
spec = json.loads((root / 'openapi.yaml').read_text())
validate_spec(spec)
manifest = json.loads((root / 'examples/manifest.json').read_text())
for example in manifest:
    schema = {'$ref': '#/components/schemas/' + example['schema'], 'components': spec['components']}
    validator = Draft202012Validator(schema, format_checker=FormatChecker())
    errors = list(validator.iter_errors(json.loads((root / 'examples' / example['file']).read_text())))
    assert (not errors) == example['valid'], (example['file'], [str(e) for e in errors])
    print(('ACCEPT ' if example['valid'] else 'REJECT ') + example['file'])
print(f"OpenAPI {spec['info']['version']} structure and {len(manifest)} examples passed.")
