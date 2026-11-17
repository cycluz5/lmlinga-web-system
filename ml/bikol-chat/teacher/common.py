"""Shared helper: one call to the cloud teacher (gemma4:31b-cloud on ollama.com), with retries.

The key is read from the project's .env (OLLAMA_API_KEY) and is never printed or stored. Only generic prompts are sent
(topics, Tagalog sentences, your team's translation examples); no user chat logs and no personal data.
"""
import json
import re
import time
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]          # the project folder (has .env)
DATASET = Path(__file__).resolve().parents[1] / "dataset"
TEACHER = DATASET / "teacher"
MODEL = "gemma4:31b-cloud"
URL = "https://ollama.com/api/chat"


class OutOfCredits(RuntimeError):
    """The cloud answered 402/429: stop cleanly, nothing is lost (the scripts are resumable)."""


def api_key():
    env = (ROOT / ".env").read_text(encoding="utf-8")
    m = re.search(r"^OLLAMA_API_KEY=(.+)$", env, re.M)
    if not m:
        raise SystemExit("OLLAMA_API_KEY is not set in .env")
    return m.group(1).strip().strip('"').strip("'")


def chat(system, user, temperature=0.7, num_predict=700, as_json=True, tries=8):
    body = {"model": MODEL, "stream": False,
            "options": {"temperature": temperature, "num_predict": num_predict, "top_p": 0.95},
            "messages": [{"role": "system", "content": system}, {"role": "user", "content": user}]}
    if as_json:
        body["format"] = "json"
    data = json.dumps(body).encode()
    last = None
    for attempt in range(tries):
        req = urllib.request.Request(URL, data, {"Content-Type": "application/json", "Authorization": "Bearer " + api_key()})
        try:
            with urllib.request.urlopen(req, timeout=120) as r:
                text = json.load(r)["message"]["content"]
            if not as_json:
                return text
            # the model often wraps the JSON in a ```json fence even when JSON is requested
            text = re.sub(r"^\s*```(?:json)?\s*|\s*```\s*$", "", text.strip())
            return json.loads(text)
        except urllib.error.HTTPError as e:
            detail = e.read()[:200]
            if e.code == 402 or (e.code == 429 and b"concurrent" not in detail):
                raise OutOfCredits(f"cloud answered {e.code}: {detail!r}")
            last = f"HTTP {e.code}"
            if e.code == 429:           # "too many concurrent requests": just wait a little and try again
                time.sleep(3 + 3 * attempt)
                tries = max(tries, 8)
                continue
        except (json.JSONDecodeError, KeyError) as e:
            last = f"bad JSON: {e}"
        except Exception as e:  # timeout, connection reset
            last = f"{type(e).__name__}: {e}"
        time.sleep(2 * (attempt + 1))
    raise RuntimeError(last)
