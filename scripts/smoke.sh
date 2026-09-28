#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Smoke test du parcours réel de la démo, à travers nginx et PHP-FPM.
#
# Les tests Pest désactivent la vérification CSRF (Laravel l'ignore pendant les
# tests unitaires) : ce script est la seule preuve que le parcours d'un vrai
# navigateur fonctionne de bout en bout.
#
#   1. GET    /sanctum/csrf-cookie   204, cookie XSRF-TOKEN
#   2. POST   /auth/demo sans jeton  419 session_expired (CSRF actif)
#   3. POST   /auth/demo             201, profil, CORS avec cookies
#   4. GET    /me                    200, même profil
#   5. DELETE /me                    204
#   6. GET    /me                    401 unauthenticated (session détruite)
#
# Variables :
#   BASE_URL  URL de l'API          (défaut : http://localhost:8000)
#   ORIGIN    origine du front      (défaut : http://localhost:3000)
#             Elle doit figurer dans SANCTUM_STATEFUL_DOMAINS et
#             CORS_ALLOWED_ORIGINS de l'API testée.
#   CLIENT_IP adresse présentée au limiteur de créations (CF-Connecting-IP)
#
# Dépendances : bash, curl, grep. Code de sortie non nul au premier échec.
# ---------------------------------------------------------------------------

set -euo pipefail

BASE_URL="${BASE_URL:-http://localhost:8000}"
ORIGIN="${ORIGIN:-http://localhost:3000}"
CLIENT_IP="${CLIENT_IP:-192.0.2.$((RANDOM % 254 + 1))}"

workdir="$(mktemp -d)"
trap 'rm -rf "$workdir"' EXIT
jar="$workdir/cookies"
body="$workdir/body"
headers="$workdir/headers"

step=0

fail() {
    echo "ÉCHEC : $*" >&2
    echo "--- en-têtes" >&2
    cat "$headers" >&2 || true
    echo "--- corps" >&2
    cat "$body" >&2 || true
    echo >&2
    exit 1
}

# request MÉTHODE CHEMIN [option curl...] : écrit le corps et les en-têtes,
# affiche le statut HTTP.
request() {
    local method="$1" path="$2"
    shift 2
    curl --silent --show-error \
        --request "$method" \
        --cookie "$jar" --cookie-jar "$jar" \
        --header "Origin: $ORIGIN" \
        --header "Referer: $ORIGIN/demo" \
        --header 'Accept: application/json' \
        --header "CF-Connecting-IP: $CLIENT_IP" \
        --dump-header "$headers" \
        --output "$body" \
        --write-out '%{http_code}' \
        "$@" \
        "$BASE_URL$path"
}

# Jeton CSRF lu dans le cookie XSRF-TOKEN, décodé de l'URL, comme le fait le
# client HTTP du front avant de le renvoyer dans X-XSRF-TOKEN.
xsrf_token() {
    local raw
    raw="$(awk '$6 == "XSRF-TOKEN" { print $7 }' "$jar" | tail -n 1)"
    [ -n "$raw" ] || fail "cookie XSRF-TOKEN absent"
    printf '%b' "${raw//%/\\x}"
}

expect_status() {
    local expected="$1" actual="$2" label="$3"
    step=$((step + 1))
    [ "$actual" = "$expected" ] || fail "$label : attendu $expected, reçu $actual"
    echo "ok $step - $label ($actual)"
}

expect_body() {
    grep -q -- "$1" "$body" || fail "corps sans $1"
}

expect_header() {
    grep -qi -- "^$1" "$headers" || fail "en-tête absent : $1"
}

status="$(request GET /sanctum/csrf-cookie)"
expect_status 204 "$status" 'GET /sanctum/csrf-cookie'
xsrf_token > /dev/null

status="$(request POST /auth/demo)"
expect_status 419 "$status" 'POST /auth/demo sans jeton CSRF'
expect_body '"code":"session_expired"'

status="$(request POST /auth/demo --header "X-XSRF-TOKEN: $(xsrf_token)")"
expect_status 201 "$status" 'POST /auth/demo'
expect_body '"is_demo":true'
expect_body '"elevations_remaining":'
expect_header "Access-Control-Allow-Origin: $ORIGIN"
expect_header 'Access-Control-Allow-Credentials: true'

status="$(request GET /me)"
expect_status 200 "$status" 'GET /me'
expect_body '"is_demo":true'
expect_header 'X-Account-Expires-At:'

status="$(request DELETE /me --header "X-XSRF-TOKEN: $(xsrf_token)")"
expect_status 204 "$status" 'DELETE /me'

status="$(request GET /me)"
expect_status 401 "$status" 'GET /me après suppression'
expect_body '"code":"unauthenticated"'

echo "smoke : parcours de la démo valide sur $BASE_URL"
