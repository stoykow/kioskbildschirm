"""Verify the uploaded release, assets, imports and application JSON APIs."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import sys
from urllib.parse import urlsplit
from urllib.request import Request, urlopen


CITY_BUS_LINES = {"A", "B", "C", "D", "E", "F", "N"}


def base_url(value: str) -> str:
    parts = urlsplit(value)
    if parts.scheme not in {"http", "https"} or not parts.hostname:
        raise ValueError("APP_URL muss eine HTTP-/HTTPS-Adresse sein")
    if parts.username or parts.password or parts.query or parts.fragment:
        raise ValueError("APP_URL darf keine Zugangsdaten, Query oder Fragment enthalten")
    return value.rstrip("/") + "/"


def application_url(environ) -> str:
    value = environ.get("APP_URL")
    if value:
        return base_url(value)
    host = environ.get("HOST") or environ.get("FTP_SERVER")
    if not host:
        raise ValueError("HOST oder APP_URL fehlt")
    return base_url(f"http://{host}:28830/")


def read(base: str, path: str, commit: str, timeout: int = 30) -> bytes:
    separator = "&" if "?" in path else "?"
    request = Request(
        base + path + separator + "ci_check=" + commit,
        headers={"Cache-Control": "no-cache", "User-Agent": "hausordnung-gitlab-ci"},
    )
    with urlopen(request, timeout=timeout) as response:
        if response.status != 200:
            raise ValueError("Unerwarteter HTTP-Status")
        return response.read()


def validate_departures(data: object, kind: str) -> None:
    if not isinstance(data, list):
        raise ValueError("Abfahrten-API liefert keine Liste")
    for departure in data:
        if not isinstance(departure, dict) or not departure.get("anzeige_zeit"):
            raise ValueError("Ungültige Abfahrt")
        if kind == "bus" and (
            departure.get("linie") not in CITY_BUS_LINES or departure.get("produkt") != "bus"
        ):
            raise ValueError("Busanzeige enthält eine unerlaubte Linie")


def verify(base: str, commit: str, artifact: Path, refresh: bool) -> None:
    marker = json.loads(read(base, "deployment_version.php", commit))
    if not isinstance(marker, dict) or marker.get("commit") != commit:
        raise ValueError("Server liefert eine andere Version als diese Pipeline")
    print("OK: Deployment-Version")

    html = read(base, "", commit).decode("utf-8")
    if any(f'id="{element}"' not in html for element in ("weather", "train", "tram", "bus")):
        raise ValueError("Kiosk-Startseite ist unvollständig")
    print("OK: Kiosk-Startseite")

    for path in sorted(artifact.iterdir()):
        if path.suffix in {".js", ".css", ".ics"}:
            delivered = read(base, path.name, commit)
            if hashlib.sha256(delivered).digest() != hashlib.sha256(path.read_bytes()).digest():
                raise ValueError(f"Server liefert eine abweichende Datei: {path.name}")
            print(f"OK: {path.name}")

    if refresh:
        result = read(base, "cron_abfahrten.php", commit, timeout=150).decode("utf-8").strip()
        if result != "OK":
            raise ValueError("Abfahrten-Import hat Fehler gemeldet")
        print("OK: Abfahrten-Import")

    for endpoint, key in (
        ("abfall_api.php", "events"),
        ("termine_sonstige_api.php", "events"),
        ("aufgaben_api.php", "tasks"),
    ):
        data = json.loads(read(base, endpoint, commit))
        if not isinstance(data, dict) or "error" in data or not isinstance(data.get(key), list):
            raise ValueError(f"Ungültige API-Antwort: {endpoint}")
        print(f"OK: {endpoint}")

    for kind in ("zug", "ersatzverkehr", "tram", "bus"):
        data = json.loads(read(base, f"abfahrten.php?typ={kind}&limit=6", commit))
        validate_departures(data, kind)
        print(f"OK: Abfahrten {kind} ({len(data)} Einträge)")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--refresh-departures", action="store_true")
    options = parser.parse_args()
    try:
        base = application_url(os.environ)
        commit = os.getenv("CI_COMMIT_SHA", "")
        if not re.fullmatch(r"[0-9a-f]{40,64}", commit):
            raise ValueError("CI_COMMIT_SHA fehlt oder ist ungültig")
        artifact = Path(__file__).resolve().parent.parent / "upload" / "htdocs"
        if not artifact.is_dir():
            raise ValueError("Webpaket fehlt")
        verify(base, commit, artifact, options.refresh_departures)
    except ValueError as error:
        print(f"Live-Prüfung fehlgeschlagen: {error}", file=sys.stderr)
        return 1
    except Exception as error:
        # Do not print response bodies, URLs with query data or credentials.
        print(f"Live-Prüfung fehlgeschlagen ({type(error).__name__})", file=sys.stderr)
        return 1
    print("Deployment und Live-Prüfung erfolgreich")
    return 0


if __name__ == "__main__":
    sys.exit(main())
