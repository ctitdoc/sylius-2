# Alma mock — the test double of Alma's backend

A FastAPI application that answers the routes the Alma PHP client calls, serves a hosted payment
page, fires the signed IPN, and offers control routes under `/_test/` for the scenarios. Moved
from the case study's workspace on 8 November 2026, so that the plugin's test bench is complete
in this repository and the double runs as a real server, over HTTP, under the client's real
transport.

- Locally: the `alma-mock` service of `compose.yml` (`task up`), reached by the PHP container at
  `http://alma-mock:8000`.
- In CI: started with uvicorn before the non-unit tests (`.github/workflows/build.yaml`), at
  `http://127.0.0.1:8000`.
- The plugin is pointed at it by `ALMA_API_URL`, which puts the client in its custom environment
  **whatever the Merchant configuration's mode, test or live**: the mode then only selects which of
  the two stored keys is sent, and the mock accepts both. One run has one target — the mock or
  Alma; the two do not coexist in a run. The compose override sets the variable for every run of
  the fork; to reach Alma's sandbox or live API by the configured mode, start the services with
  it empty (`ALMA_API_URL= docker compose up -d`) — the demo shop of the workspace does the
  reverse, sandbox by default and the mock on demand.

Every API key of the form `sk_test_…` / `sk_live_…` of eight characters or more is accepted.
