"""
Alma's API, simulated — the one test double of the Alma backend, for the plugin's test bench.

It imitates the part of Alma's API that the PHP client v3 calls from a plugin — the merchant,
its fee plans, the eligibility of a purchase, the creation, reading, cancellation and refund of a
payment, the business events, the potential-fraud flag — and stands for the hosted payment page:
the Buyer sees the schedule, pays or gives up, and the simulator then does what Alma does — the
signed server-to-server notification (IPN), then the browser's return to the shop.

The JSON shapes are those the client's entities require (`Alma\Client\Domain\Entity\*`), per
https://docs.almapay.com/reference. Everything is in memory: restarting the server forgets the
payments. Routes under `/_test/` are for the scenarios of the Test Application: reset, pay a
payment without a browser, read what the plugin flagged as fraud.

Run: `uvicorn app:app --host 127.0.0.1 --port 8000` (see requirements.txt), or the `alma-mock`
service of the fork's compose. Point the plugin at it with `ALMA_API_URL`.
"""
from __future__ import annotations

import base64
import hashlib
import hmac
import os
import time
import uuid
from urllib.parse import quote, urlencode

import httpx
from fastapi import FastAPI, Header, HTTPException, Request
from fastapi.responses import HTMLResponse, JSONResponse, RedirectResponse

PUBLIC_URL = os.environ.get("PUBLIC_URL", "http://localhost:8001").rstrip("/")
# La boutique génère ses URL absolues pour le navigateur ; depuis le réseau Docker, le
# simulateur doit les joindre par le nom du service nginx. Même geste que ferait un tunnel.
IPN_REWRITE_FROM = os.environ.get("IPN_REWRITE_FROM", "")
IPN_REWRITE_TO = os.environ.get("IPN_REWRITE_TO", "")

app = FastAPI(title="Alma API — simulateur", version="0.1")

MERCHANT = {"id": "merchant_demo_0001", "name": "Boutique de démonstration", "can_create_payments": True}

# Plans de paiement du marchand : (nombre d'échéances, jours différés, mois différés) ->
# bornes du panier en centimes, frais client en points de base, frais marchand.
FEE_PLANS = [
    dict(installments_count=1, deferred_days=0, deferred_months=0, allowed=False, min=5000, max=200000, cfee=0, mfee=150),
    dict(installments_count=2, deferred_days=0, deferred_months=0, allowed=True, min=5000, max=200000, cfee=0, mfee=380),
    dict(installments_count=3, deferred_days=0, deferred_months=0, allowed=True, min=5000, max=200000, cfee=0, mfee=380),
    dict(installments_count=4, deferred_days=0, deferred_months=0, allowed=True, min=5000, max=200000, cfee=0, mfee=420),
    dict(installments_count=1, deferred_days=15, deferred_months=0, allowed=True, min=5000, max=100000, cfee=0, mfee=250),
    dict(installments_count=1, deferred_days=30, deferred_months=0, allowed=True, min=5000, max=100000, cfee=0, mfee=250),
    dict(installments_count=10, deferred_days=0, deferred_months=0, allowed=True, min=50000, max=400000, cfee=550, mfee=0),
    dict(installments_count=12, deferred_days=0, deferred_months=0, allowed=True, min=50000, max=400000, cfee=650, mfee=0),
]

PAYMENTS: dict[str, dict] = {}
KEYS_SEEN: dict[str, str] = {}  # payment id -> the API key that created it (signs the IPN)
FRAUD_FLAGS: list[dict] = []    # what the plugin reported through /v1/payments/{id}/potential-fraud


def auth(authorization: str | None) -> str:
    """Une clé d'API est acceptée si elle a la forme d'une clé Alma ; sa valeur importe peu."""
    if not authorization or not authorization.startswith("Alma-Auth "):
        raise HTTPException(401, {"message": "Credentials invalid or not supplied"})
    key = authorization.removeprefix("Alma-Auth ").strip()
    if len(key) < 8:
        raise HTTPException(401, {"message": "Credentials invalid or not supplied"})
    return key


def plan_for(installments: int, days: int, months: int) -> dict | None:
    for p in FEE_PLANS:
        if (p["installments_count"], p["deferred_days"], p["deferred_months"]) == (installments, days, months):
            return p
    return None


def fee_plan_json(p: dict) -> dict:
    return {
        "allowed": p["allowed"], "available_online": p["allowed"], "kind": "general",
        "installments_count": p["installments_count"], "deferred_days": p["deferred_days"],
        "deferred_months": p["deferred_months"], "min_purchase_amount": p["min"],
        "max_purchase_amount": p["max"], "customer_fee_variable": p["cfee"],
        "customer_fee_fixed": 0, "merchant_fee_variable": p["mfee"], "merchant_fee_fixed": 0,
        "customer_lending_rate": 0,
    }


def schedule(amount: int, p: dict, start: float) -> tuple[list[dict], int]:
    """L'échéancier : la part de chaque échéance, les frais client à la première."""
    n = p["installments_count"]
    base, rest = divmod(amount, n)
    customer_fee = amount * p["cfee"] // 10000
    first_due = start + p["deferred_days"] * 86400 + p["deferred_months"] * 30 * 86400
    plan = []
    for i in range(n):
        part = base + (1 if i < rest else 0)
        plan.append({
            "due_date": int(first_due + i * 30 * 86400),
            "purchase_amount": part,
            "customer_fee": customer_fee if i == 0 else 0,
            "customer_interest": 0,
            "total_amount": part + (customer_fee if i == 0 else 0),
        })
    return plan, customer_fee


def kind_of(p: dict) -> str:
    k = f"P{p['installments_count']}X"
    if p["deferred_days"]:
        k += f"_D+{p['deferred_days']}"
    if p["deferred_months"]:
        k += f"_M+{p['deferred_months']}"
    return k


# ----------------------------------------------------------------- API appelée par le plugin

@app.get("/v1/me")
@app.get("/v1/me/extended-data")  # c'est ce chemin que le client PHP v3 appelle pour « me() »
def me(authorization: str | None = Header(default=None)):
    auth(authorization)
    return MERCHANT


@app.get("/v1/me/fee-plans")
def fee_plans(kind: str = "general", only: str = "all", deferred: str = "false",
              authorization: str | None = Header(default=None)):
    auth(authorization)
    plans = [p for p in FEE_PLANS if deferred == "true" or (p["deferred_days"] == 0 and p["deferred_months"] == 0)]
    if only != "all":
        wanted = {int(x) for x in only.split(",") if x.strip().isdigit()}
        plans = [p for p in plans if p["installments_count"] in wanted]
    return [fee_plan_json(p) for p in plans]


@app.post("/v1/me/business-events", status_code=204)
async def business_events(request: Request, authorization: str | None = Header(default=None)):
    auth(authorization)
    await request.json()
    return None


@app.post("/v2/payments/eligibility")
async def eligibility(request: Request, authorization: str | None = Header(default=None)):
    auth(authorization)
    body = await request.json()
    amount = int(body.get("purchase_amount", 0))
    out = []
    for q in body.get("queries", []):
        n, d, m = int(q.get("installments_count", 1)), int(q.get("deferred_days", 0)), int(q.get("deferred_months", 0))
        p = plan_for(n, d, m)
        common = {"installments_count": n, "deferred_days": d, "deferred_months": m}
        if p is None or not p["allowed"] or not (p["min"] <= amount <= p["max"]):
            lo, hi = (p["min"], p["max"]) if p else (0, 0)
            out.append({"eligible": False, **common,
                        "constraints": {"purchase_amount": {"minimum": lo, "maximum": hi}},
                        "reasons": {"purchase_amount": "invalid_value"}})
            continue
        plan, cfee = schedule(amount, p, time.time())
        out.append({"eligible": True, **common, "customer_fee": cfee,
                    "customer_total_cost_amount": cfee,
                    "customer_total_cost_bps": p["cfee"], "annual_interest_rate": 0,
                    "payment_plan": plan})
    return out


@app.post("/v1/payments", status_code=201)
async def create_payment(request: Request, authorization: str | None = Header(default=None)):
    key = auth(authorization)
    body = await request.json()
    pay = body.get("payment", {})
    amount = int(pay.get("purchase_amount", 0))
    n, d, m = int(pay.get("installments_count", 1)), int(pay.get("deferred_days", 0)), int(pay.get("deferred_months", 0))
    p = plan_for(n, d, m)
    if p is None or not p["allowed"] or not (p["min"] <= amount <= p["max"]):
        raise HTTPException(400, {"message": "Purchase amount not eligible for this fee plan"})
    pid = "payment_" + uuid.uuid4().hex[:20]
    now = time.time()
    plan, cfee = schedule(amount, p, now)
    for row in plan:  # a payment's instalments carry a state; an eligibility's rows, a forecast, carry none
        row["state"] = "pending"
    order = body.get("order", {}) or {}
    payment = {
        "id": pid, "state": "in_progress", "processing_status": "awaiting_authorization",
        "url": f"{PUBLIC_URL}/pay/{pid}", "purchase_amount": amount, "installments_count": n,
        "deferred_days": d, "deferred_months": m, "kind": kind_of(p), "origin": pay.get("origin", "online"),
        "custom_data": pay.get("custom_data", {}) or {}, "customer_fee": cfee, "customer_interest": 0,
        "amount_already_refunded": 0, "expired_at": int(now + 3600), "created": int(now),
        "orders": [{"id": "order_" + uuid.uuid4().hex[:12], "merchant_reference": order.get("merchant_reference"),
                    "merchant_url": order.get("merchant_url"), "customer_url": order.get("customer_url"),
                    "data": order.get("data", {}) or {}, "created": int(now)}],
        "payment_plan": plan, "return_url": pay.get("return_url"), "customer_cancel_url": pay.get("customer_cancel_url"),
        "ipn_callback_url": pay.get("ipn_callback_url"), "locale": pay.get("locale", "fr_FR"),
        "customer": body.get("customer", {}) or {}, "billing_address": pay.get("billing_address"),
        "shipping_address": pay.get("shipping_address"), "cart": pay.get("cart"),
        "refunds": [], "ipn": [],
    }
    PAYMENTS[pid] = payment
    KEYS_SEEN[pid] = key
    return payment


def get_or_404(pid: str) -> dict:
    if pid not in PAYMENTS:
        raise HTTPException(404, {"message": "Payment not found"})
    return PAYMENTS[pid]


@app.get("/v1/payments/{pid}")
def fetch_payment(pid: str, authorization: str | None = Header(default=None)):
    auth(authorization)
    return get_or_404(pid)


@app.put("/v1/payments/{pid}/cancel", status_code=204)
def cancel_payment(pid: str, authorization: str | None = Header(default=None)):
    auth(authorization)
    p = get_or_404(pid)
    p["processing_status"] = "canceled"
    p["cancelation_reason"] = "requested_by_merchant"
    return None


@app.post("/v1/payments/{pid}/refund")
async def refund_payment(pid: str, request: Request, authorization: str | None = Header(default=None)):
    auth(authorization)
    p = get_or_404(pid)
    body = await request.json() if int(request.headers.get("content-length", "0") or 0) > 0 else {}
    amount = int(body.get("amount") or (p["purchase_amount"] - p["amount_already_refunded"]))
    if amount <= 0 or p["amount_already_refunded"] + amount > p["purchase_amount"]:
        raise HTTPException(400, {"message": "Refund amount exceeds the refundable amount"})
    p["amount_already_refunded"] += amount
    p["refunds"].append({"id": "refund_" + uuid.uuid4().hex[:12], "amount": amount,
                         "merchant_reference": body.get("merchant_reference"), "comment": body.get("comment"),
                         "created": int(time.time())})
    return p


@app.post("/v1/payments/{pid}/potential-fraud", status_code=204)
async def potential_fraud(pid: str, request: Request, authorization: str | None = Header(default=None)):
    auth(authorization)
    get_or_404(pid)
    body = await request.json() if int(request.headers.get("content-length", "0") or 0) > 0 else {}
    FRAUD_FLAGS.append({"payment_id": pid, "reason": body.get("reason"), "at": int(time.time())})
    return None


# ----------------------------------------------------------- la page de paiement hébergée

def sign(params: dict[str, str], secret: str) -> str:
    """Même algorithme que WebhookHelper::verifySignature du client PHP v3."""
    data = "&".join(f"{k}={quote(str(v), safe='')}" for k, v in sorted(params.items()))
    digest = hmac.new(secret.encode(), data.encode(), hashlib.sha256).digest()
    return base64.urlsafe_b64encode(digest).decode().rstrip("=")


def rewrite(url: str) -> str:
    if IPN_REWRITE_FROM and url.startswith(IPN_REWRITE_FROM):
        return IPN_REWRITE_TO + url[len(IPN_REWRITE_FROM):]
    return url


def euros(cents: int) -> str:
    return f"{cents / 100:,.2f} €".replace(",", " ").replace(".", ",")


@app.get("/pay/{pid}", response_class=HTMLResponse)
def hosted_page(pid: str):
    p = get_or_404(pid)
    rows = "".join(
        f"<tr><td>{time.strftime('%d/%m/%Y', time.localtime(i['due_date']))}</td>"
        f"<td style='text-align:right'>{euros(i['total_amount'])}</td></tr>" for i in p["payment_plan"])
    status = p["processing_status"]
    actions = (
        f"<form method='post' action='/pay/{pid}/accept' style='display:inline'><button>Payer la première échéance</button></form> "
        f"<form method='post' action='/pay/{pid}/decline' style='display:inline'><button>Refus simulé</button></form> "
        f"<a href='/pay/{pid}/cancel'>Annuler et revenir à la boutique</a>"
        if status == "awaiting_authorization" else f"<p>Ce paiement est <b>{status}</b>.</p>"
    )
    return f"""<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Alma — simulateur</title>
    <style>body{{font-family:system-ui;max-width:560px;margin:3rem auto;padding:0 1rem;color:#222}}
    table{{border-collapse:collapse;width:100%}}td{{padding:.4rem .6rem;border-bottom:1px solid #eee}}
    button{{background:#fa5022;color:#fff;border:0;padding:.6rem 1rem;border-radius:6px;font-size:1rem;cursor:pointer}}
    .tag{{background:#ffe9e0;color:#fa5022;padding:.2rem .5rem;border-radius:4px;font-size:.8rem}}</style></head>
    <body><span class="tag">SIMULATEUR — aucun argent ne circule</span>
    <h1>Payer {euros(p['purchase_amount'])} en {p['kind']}</h1>
    <p>Commande <b>{p['orders'][0].get('merchant_reference') or '—'}</b> · identifiant <code>{pid}</code></p>
    <table>{rows}</table>
    <p style="margin-top:1.5rem">{actions}</p></body></html>"""


async def fire_ipn(p: dict) -> None:
    url = p.get("ipn_callback_url")
    if not url:
        return
    params = {"pid": p["id"]}
    target = rewrite(url) + ("&" if "?" in url else "?") + urlencode(params)
    headers = {"X-Alma-Signature": sign(params, KEYS_SEEN.get(p["id"], ""))}
    try:
        async with httpx.AsyncClient(timeout=10, follow_redirects=True) as client:
            r = await client.get(target, headers=headers)
        p["ipn"].append({"url": target, "status": r.status_code, "at": int(time.time())})
    except Exception as exc:  # une boutique injoignable ne doit pas casser la page
        p["ipn"].append({"url": target, "error": repr(exc), "at": int(time.time())})


@app.post("/pay/{pid}/accept")
async def accept(pid: str):
    p = get_or_404(pid)
    p["processing_status"] = "captured"
    p["payment_plan"][0]["state"] = "paid"
    await fire_ipn(p)
    sep = "&" if "?" in (p.get("return_url") or "") else "?"
    return RedirectResponse(f"{p['return_url']}{sep}pid={pid}", status_code=303)


@app.post("/pay/{pid}/decline")
async def decline(pid: str):
    p = get_or_404(pid)
    p["processing_status"] = "canceled"
    p["cancelation_reason"] = "declined"
    sep = "&" if "?" in (p.get("customer_cancel_url") or "") else "?"
    return RedirectResponse(f"{p['customer_cancel_url']}{sep}pid={pid}", status_code=303)


@app.get("/pay/{pid}/cancel")
def cancel_by_customer(pid: str):
    p = get_or_404(pid)
    sep = "&" if "?" in (p.get("customer_cancel_url") or "") else "?"
    return RedirectResponse(f"{p['customer_cancel_url']}{sep}pid={pid}", status_code=303)


# --------------------------------------------------- control routes for the Test Application

@app.post("/_test/reset", status_code=204)
def test_reset():
    """Forget every payment and flag: a scenario starts clean."""
    PAYMENTS.clear(); KEYS_SEEN.clear(); FRAUD_FLAGS.clear()
    return None


@app.post("/_test/payments/{pid}/pay")
async def test_pay(pid: str):
    """What the Buyer does on the hosted page, without a browser: first instalment paid, IPN fired."""
    p = get_or_404(pid)
    p["processing_status"] = "captured"
    p["payment_plan"][0]["state"] = "paid"
    await fire_ipn(p)
    return p


@app.post("/_test/payments/{pid}/decline")
def test_decline(pid: str):
    p = get_or_404(pid)
    p["processing_status"] = "canceled"
    p["cancelation_reason"] = "declined"
    return p


@app.get("/_test/fraud-flags")
def test_fraud_flags():
    return FRAUD_FLAGS


# ------------------------------------------------------------------------ pour la démo

@app.get("/")
def index():
    """L'état du simulateur : ce qu'un tableau de bord Alma montrerait."""
    return JSONResponse({"merchant": MERCHANT, "payments": list(PAYMENTS.values())})
