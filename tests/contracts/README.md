# UI ↔ API client contracts

Machine-checked registry so server-required request fields cannot ship without matching **web** and **companion** UI.

- Schema: `scripts/ui-api-client-contracts.schema.json` (workspace root)
- Checker: `./scripts/check-ui-api-client-contracts.py --heuristic`
- Agent rule: `.cursor/rules/ui-api-client-contract.mdc`
- Decision: `documentation/arbeitszeitcheck/decisions/20260917-ui-api-client-contracts.md`

When you add a required `justification` / `reason` / similar field: update this JSON, wire every surface, re-run the checker.
