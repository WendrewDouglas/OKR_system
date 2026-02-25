import os, json, urllib.parse
from flask import Flask, request, redirect
import requests
from dotenv import load_dotenv

load_dotenv()
CLIENT_ID = os.getenv("MIRO_CLIENT_ID")
CLIENT_SECRET = os.getenv("MIRO_CLIENT_SECRET")
REDIRECT_URI = os.getenv("MIRO_REDIRECT_URI", "http://localhost:5000/callback")

AUTH_URL = "https://miro.com/oauth/authorize"
TOKEN_URL = "https://api.miro.com/v1/oauth/token"  # token endpoint atual
# doc: usar Authorization Code → trocar code por access/refresh :contentReference[oaicite:3]{index=3}

app = Flask(__name__)

@app.route("/")
def index():
    params = {
        "response_type": "code",
        "client_id": CLIENT_ID,
        "redirect_uri": REDIRECT_URI
        # se quiser restringir escopos, adicione: "scope": "boards:write"
    }
    url = f"{AUTH_URL}?{urllib.parse.urlencode(params)}"
    return f'<a href="{url}">Autorizar na Miro</a>'

@app.route("/callback")
def callback():
    code = request.args.get("code")
    if not code:
        return "Sem code na URL", 400
    data = {
        "grant_type": "authorization_code",
        "client_id": CLIENT_ID,
        "client_secret": CLIENT_SECRET,
        "redirect_uri": REDIRECT_URI,
        "code": code
    }
    r = requests.post(TOKEN_URL, data=data)
    if r.status_code != 200:
        return f"Falha ao trocar code: {r.status_code} {r.text}", 400
    tokens = r.json()
    with open("tokens.json", "w", encoding="utf-8") as f:
        json.dump(tokens, f, ensure_ascii=False, indent=2)
    return "Tokens salvos em tokens.json. Você pode fechar esta aba."

if __name__ == "__main__":
    app.run(port=5000, debug=False)
