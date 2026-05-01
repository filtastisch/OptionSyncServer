# Options Sync Server

Kleine PHP-API fuer den Minecraft Options Sync Mod. Die API speichert pro Minecraft-UUID ein `options`-Array in SQLite und gibt es im gleichen JSON-Format wieder zurueck.

## Start

```bash
docker compose up -d --build
```

Die API ist danach lokal unter `http://localhost:8080` erreichbar.

## Konfiguration

Setze in `docker-compose.yml` einen eigenen Token:

```yaml
environment:
  BEARER_TOKEN: "CHANGE_ME_SUPER_SECRET_BEARER_KEY"
  DATABASE_PATH: "/data/options.sqlite"
```

Passe im Mod danach diese Konstanten an:

```java
private static final URI SERVER_BASE_URI = URI.create("http://localhost:8080");
private static final String SETTINGS_PATH = "/api/options/";
private static final String BEARER_TOKEN = "CHANGE_ME_SUPER_SECRET_BEARER_KEY";
```

Fuer Produktion sollte `SERVER_BASE_URI` auf deine HTTPS-Domain zeigen.

## API

Alle Requests muessen diese Header enthalten:

```http
Authorization: Bearer CHANGE_ME_SUPER_SECRET_BEARER_KEY
X-Minecraft-UUID: 00000000-0000-0000-0000-000000000000
Accept: application/json
```

### Optionen herunterladen

```bash
curl -X GET "http://localhost:8080/api/options/00000000-0000-0000-0000-000000000000" \
  -H "Authorization: Bearer CHANGE_ME_SUPER_SECRET_BEARER_KEY" \
  -H "X-Minecraft-UUID: 00000000-0000-0000-0000-000000000000" \
  -H "Accept: application/json"
```

Antwort:

```json
{
  "options": [
    {
      "key": "version",
      "value": "4189"
    },
    {
      "key": "autoJump",
      "value": "false"
    }
  ]
}
```

Wenn fuer die UUID noch nichts gespeichert wurde, kommt `{"options":[]}` zurueck.

### Optionen hochladen

```bash
curl -X PUT "http://localhost:8080/api/options/00000000-0000-0000-0000-000000000000" \
  -H "Authorization: Bearer CHANGE_ME_SUPER_SECRET_BEARER_KEY" \
  -H "X-Minecraft-UUID: 00000000-0000-0000-0000-000000000000" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"options":[{"key":"version","value":"4189"},{"key":"autoJump","value":"false"}]}'
```
