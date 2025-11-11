from pathlib import Path
import os, pymysql
from dotenv import load_dotenv

# carrega o mesmo .env do PHP
env_path = Path(__file__).resolve().parent.parent / ".env"
load_dotenv(env_path)

host = os.getenv("DB_HOST", "localhost")
port = int(os.getenv("DB_PORT", "3306"))
user = os.getenv("DB_USER")
pw   = os.getenv("DB_PASS")
db   = os.getenv("DB_NAME")
charset = os.getenv("DB_CHARSET", "utf8mb4")

print(f"[DEBUG] tentando conectar em {host}:{port} db={db} user={user}")

conn = pymysql.connect(
    host=host, port=port, user=user, password=pw, database=db, charset=charset
)
with conn.cursor() as c:
    c.execute("SELECT DATABASE(), VERSION()")
    print("[OK]", c.fetchone())
conn.close()
