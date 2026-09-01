# Connector channel MVP (Control Plane)

This documents the Laravel slice that turns `mining-drilling-api` into a **Control Plane mailbox** for `undersurf-connector`.

## Endpoints

| Method | Path | Auth |
|--------|------|------|
| POST | `/api/connector/v1/enroll` | enrollment token (body) |
| POST | `/api/connector/v1/sessions` | connector_id + connector_token (body) |
| POST | `/api/connector/v1/heartbeat` | Bearer connector token |
| POST | `/api/connector/v1/poll` | Bearer connector token |
| POST | `/api/connector/v1/results` | Bearer connector token |
| POST | `/api/internal/demo/list-holes` | Bearer `DEMO_INTERNAL_TOKEN` |

## Domain op

Only `drill_holes.list@1` is dispatched by the demo endpoint. Control Plane never sends SQL.

## Local runbook

See `../undersurf-connector/docs/RUNBOOK_LOCAL.md` (sibling repo).

## DigitalOcean

See `DEPLOY_MVP_DIGITALOCEAN.md` (planning only — no auto-deploy).
