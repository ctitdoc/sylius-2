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
  whatever the configured mode.

Every API key of the form `sk_test_…` / `sk_live_…` of eight characters or more is accepted.
