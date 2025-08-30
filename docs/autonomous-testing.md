# Autonomous Chat Testing System

Dit document beschrijft het autonomous testing system voor de church chat service, specifiek ontworpen om Claude Code in staat te stellen zelfstandig de chat service te testen, analyseren en verbeteren.

## Overzicht

Het testing system bestaat uit:
- **TestChatCommand** - CLI tool voor testing
- **TestChatService** - Service voor mock members en API calls
- **chat-scenarios.yaml** - Test cases definitie
- **Makefile integratie** - Eenvoudige commands

## Quick Start

### Basis Commands

```bash
# Test alle scenarios (JSON output voor Claude Code analyse)
make test-chat

# Test specifieke input
make test-chat-input INPUT="Mijn naam is Renko"

# Test specifiek scenario
make test-chat-scenario SC=name_recognition

# Dry run om te zien wat er getest zou worden
docker exec church-chat-app php bin/console app:test-chat --dry-run
```

### Voor Claude Code Autonomous Testing

```bash
# Volledige test suite met analyse data
make test-chat > test-results.json

# Parseer resultaten en analyseer
# Claude Code kan de JSON output direct analyseren voor:
# - Success rates per scenario
# - Performance metrics
# - Tool calling validatie
# - Response quality metrics
```

## Test Scenarios

De test scenarios zijn gedefinieerd in `tests/chat-scenarios.yaml` en omvatten:

### Core Functionality Tests
- **name_recognition** - Test naam herkenning en manage_user tool
- **user_profile_management** - Test profiel updates (leeftijd, kerk, doelgroep)
- **theological_questions** - Test theologische vragen en answer_question tool
- **sermon_attendance_positive/negative** - Test sermon attendance handling

### Advanced Tests  
- **combined_information** - Test berichten met meerdere info elementen
- **informal_language** - Test omgang met informeel Nederlands
- **error_recovery** - Test afhandeling van onduidelijke input
- **ambiguous_responses** - Test context-afhankelijke responses

### Subscription & Support
- **subscription_management** - Test notificatie instellingen
- **feedback_processing** - Test feedback en support afhandeling

## JSON Output Format

Het command geeft machine-readable JSON output voor autonomous analyse:

```json
{
  "test_run": {
    "timestamp": "2025-01-30T19:50:49+01:00",
    "total_time_ms": 45000,
    "profile": "volwassen",
    "scenarios_tested": 13,
    "total_inputs_tested": 68,
    "success_rate": 0.85,
    "results": [
      {
        "scenario": "name_recognition",
        "input": "Mijn naam is Renko", 
        "expected_tool_calls": ["manage_user"],
        "actual_tool_calls": ["manage_user"],
        "response": null,
        "success": true,
        "latency_ms": 4486,
        "validation_results": {
          "success": true,
          "validations": {
            "tool_calls": {
              "expected": ["manage_user"],
              "actual": ["manage_user"],
              "success": true
            }
          }
        }
      }
    ],
    "analysis": {
      "summary": {
        "total_tests": 68,
        "successful_tests": 58,
        "success_rate": 0.85,
        "avg_latency_ms": 2200,
        "p95_latency_ms": 4800
      },
      "common_failures": ["Context handling", "Tool trigger sensitivity"],
      "performance": {
        "fastest_ms": 800,
        "slowest_ms": 6200
      }
    }
  }
}
```

## Member Profiles

Het system ondersteunt verschillende member profiles voor testing:

- **volwassen** (default) - Standaard volwassen doelgroep
- **jongeren** - Jongeren doelgroep met informelere toon
- **verdieping** - Theologische verdieping met bijbelverwijzingen

```bash
# Test met verschillende profielen
docker exec church-chat-app php bin/console app:test-chat --input="Wat betekent genade?" --member-profile=verdieping
```

## Claude Code Autonomous Workflow

### 1. Test Suite Uitvoeren
```bash
make test-chat > results.json
```

### 2. Resultaten Analyseren
Claude Code kan de JSON output analyseren voor:
- **Success Rate**: Welke scenarios falen structureel?
- **Tool Calling**: Worden de juiste tools aangeroepen?
- **Response Quality**: Zijn responses lang/informatief genoeg?
- **Performance**: Zijn er latency issues?

### 3. Problemen Identificeren
Veelvoorkomende issues die Claude Code kan detecteren:
- Tools niet getriggered door context problemen
- Te korte responses bij complexe vragen
- Slechte afhandeling van informele taal
- Performance degradatie

### 4. Fixes Implementeren
Claude Code kan dan:
- **Prompts aanpassen** in `docs/request_design.json`
- **Tool descriptions verbeteren** in OpenAIService
- **Context handling fixes** in services
- **Response validation verbeteren**

### 5. Regression Testing
```bash
# Test specifiek gefaalde scenario na fix
make test-chat-scenario SC=failed_scenario_name

# Volledige retest
make test-chat
```

## Performance Targets

De test configuratie definieert performance targets:

```yaml
test_config:
  default_timeout_ms: 30000
  max_retries: 2
  success_threshold: 0.75  # 75% success rate vereist
  performance_targets:
    avg_latency_ms: 2000   # Gemiddeld onder 2 seconden
    p95_latency_ms: 5000   # 95% onder 5 seconden
```

## Troubleshooting

### Common Issues

1. **OpenAI API Errors**
   - Check API key configuratie
   - Verify network connectivity

2. **Tool Not Triggered** 
   - Check prompt instructions in `docs/request_design.json`
   - Verify tool descriptions zijn duidelijk
   - Test met verbose output: `--verbose-errors`

3. **Database Connection Issues**
   - Ensure MySQL container is running
   - Check connection string in docker-compose.yml

### Debug Commands

```bash
# Verbose error output
docker exec church-chat-app php bin/console app:test-chat --input="test" --verbose-errors

# Check available scenarios
docker exec church-chat-app php bin/console app:test-chat --dry-run

# Test connectivity
docker exec church-chat-app php bin/console app:test-chat --input="ping"
```

## Extending Test Scenarios

Voeg nieuwe scenarios toe in `tests/chat-scenarios.yaml`:

```yaml
scenarios:
  - name: my_new_scenario
    description: "Test description"
    inputs:
      - "Test input 1"
      - "Test input 2"
    expected:
      tool_calls: ["expected_tool"]
      response_contains: ["expected", "text"]
      response_min_length: 50
    setup:
      context: "optional context setup"
```

## Integration met CI/CD

Voor geautomatiseerde testing in CI/CD:

```bash
#!/bin/bash
# Run chat tests and validate success rate
RESULTS=$(make test-chat)
SUCCESS_RATE=$(echo "$RESULTS" | jq '.test_run.success_rate')

if (( $(echo "$SUCCESS_RATE < 0.75" | bc -l) )); then
    echo "Chat tests failed with success rate: $SUCCESS_RATE"
    exit 1
fi

echo "Chat tests passed with success rate: $SUCCESS_RATE"
```

Dit testing system stelt Claude Code in staat om autonoom de chat service te testen, problemen te identificeren, en iteratief verbeteringen door te voeren zonder menselijke interventie.