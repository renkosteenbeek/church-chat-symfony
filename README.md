# Church Chat Service

Dit is de chat service voor het Church Media Platform, een Symfony applicatie die draait in Docker containers.

## Xdebug Configuratie

Deze applicatie heeft Xdebug 3.4.5 geïnstalleerd voor debugging en ontwikkeling.

### Xdebug Activeren

**Voor Development:**
```bash
# Start containers met development configuratie
docker-compose --env-file .env.dev up -d
```

**Voor Production:**
Xdebug is standaard uitgeschakeld in productie. Verander de environment variables niet in productie.

### IDE Configuratie

#### PHPStorm Setup

1. **Server Configuration:**
   - Ga naar File → Settings → Languages & Frameworks → PHP → Servers
   - Klik op "+" om een nieuwe server toe te voegen
   - Name: `church-chat`
   - Host: `localhost`
   - Port: `8100`
   - Use path mappings: ✓
   - Project root: `/app`

2. **Debug Configuration:**
   - Ga naar File → Settings → Languages & Frameworks → PHP → Debug
   - Xdebug port: `9003`
   - IDE key: `PHPSTORM_CHURCH_CHAT`

3. **Start Listening:**
   - Klik op de telefoon icon in de toolbar om te luisteren naar debug connections
   - Of gebruik Run → Start Listening for PHP Debug Connections

#### VS Code Setup

1. **Installeer PHP Debug Extension:**
   ```bash
   ext install felixfbecker.php-debug
   ```

2. **Launch Configuration (.vscode/launch.json):**
   ```json
   {
     "version": "0.2.0",
     "configurations": [
       {
         "name": "Listen for Xdebug",
         "type": "php",
         "request": "launch",
         "port": 9003,
         "pathMappings": {
           "/app": "${workspaceFolder}"
         },
         "xdebugSettings": {
           "idekey": "PHPSTORM_CHURCH_CHAT"
         }
       }
     ]
   }
   ```

### Environment Variables

| Variable | Development | Production | Beschrijving |
|----------|-------------|------------|-------------|
| `CONFIGURE_XDEBUG` | `1` | `0` | Activeert Xdebug configuratie |
| `XDEBUG_MODE` | `develop,debug` | `off` | Xdebug mode instellingen |
| `XDEBUG_CONFIG` | `idekey=PHPSTORM_CHURCH_CHAT` | n/a | IDE key voor debugging |

### Debugging Testen

1. **Start containers met development config:**
   ```bash
   docker-compose --env-file .env.dev up -d
   ```

2. **Controleer Xdebug status:**
   ```bash
   docker exec church-chat-app php -r "xdebug_info();"
   ```

3. **Plaats breakpoint in code:**
   - Open een Symfony controller
   - Plaats een breakpoint
   - Maak een HTTP request naar je applicatie

4. **Verificeer verbinding:**
   - IDE zou moeten stoppen bij breakpoint
   - Je kunt variabelen inspecteren en code stappen

### Troubleshooting

**Xdebug verbindt niet:**
- Controleer of port 9003 niet geblokkeerd is door firewall
- Verificeer of `host.docker.internal` bereikbaar is
- Check container logs: `docker logs church-chat-app`

**IDE vindt bestanden niet:**
- Controleer path mappings: `/app` → project root
- Zorg ervoor dat server name `church-chat` correct is geconfigureerd

**Performance problemen:**
- Xdebug is alleen actief in development mode
- Voor productie: gebruik `XDEBUG_MODE=off`