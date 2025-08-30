# Church Chat Symfony - Implementation Notes

## Geïmplementeerde Features

Dit project implementeert een volledige church chat service met OpenAI GPT-5 nano integratie volgens de specificaties in /docs.

### 1. OpenAI GPT-5 Nano API Integration
- **Nieuwe endpoints**: `/conversations` en `/responses`
- **Proactieve tool detection** met automatische actie uitvoering
- **Vector store integratie** voor preek content search
- **Conversation persistence**: gesprekken blijven actief tot nieuwe content

### 2. Tool System
Volledig geïmplementeerde tools:
- **manage_user**: Update gebruikersinformatie (naam, leeftijd, doelgroep)
- **handle_sermon**: Preek attendance registratie en samenvattingen
- **manage_subscription**: Notificatie voorkeuren beheer
- **answer_question**: Theologische vragen beantwoording
- **process_feedback**: Feedback en klachten verwerking
- **file_search**: Vector store doorzoeken voor preek content

### 3. Content Distribution Flow
- **Queue processing** met optionele parallel verwerking
- **Status management**: scheduled → waiting → queued → sent
- **Multi-church handling**: members met meerdere kerken krijgen status "waiting"
- **Conversation lifecycle**: nieuwe conversation alleen bij nieuwe sermon

### 4. Signal Integration
- **Two-way communication** via Signal API
- **Tool call flow**: message → tool detection → execution → response
- **Recursive tool handling** voor multiple tool calls

### 5. Complete Tool Implementation
**All tools now fully functional with ContentApiClient integration:**
- **manage_user**: Updates member info, validates church names via API
- **handle_sermon**: Fetches summaries, registers attendance, handles absences
- **manage_subscription**: Manages notification preferences with persistence
- **answer_question**: Logs theological questions with vector store integration
- **process_feedback**: Routes feedback to admin via Content Service API
- **Enhanced error handling**: Graceful degradation when services unavailable
- **Comprehensive validation**: Argument validation with detailed error messages
- **Performance monitoring**: Execution time tracking and logging

## Deployment Instructies

### 1. Database Migration
```bash
# Start de database containers
cd church-infrastructure
docker-compose up -d

# Run migration
cd ../church-chat-symfony
docker exec -it church-chat-symfony php bin/console doctrine:migrations:migrate
```

### 2. Environment Variables
Zorg dat deze environment variables zijn ingesteld:
```env
OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-5-nano
SIGNAL_SERVICE_URL=http://church-signal-service:8104
SIGNAL_NUMBER=+31682016353
CONTENT_SERVICE_URL=http://church-content-service:8101
PARALLEL_PROCESSES=3
```

### 3. Start Services
```bash
# Start alle services
docker-compose up -d

# Check logs
docker logs -f church-chat-symfony
```

### 4. Process Content Queue
```bash
# Eenmalige run
docker exec -it church-chat-symfony php bin/console app:process-content-queue

# Continue processing
docker exec -it church-chat-symfony php bin/console app:process-content-queue --continuous

# Met parallel processing
docker exec -it church-chat-symfony php bin/console app:process-content-queue --parallel --continuous
```

## Testing

### Comprehensive Tool Testing

#### 1. List Available Tools
```bash
curl -X GET http://localhost:8100/api/v1/chat/test/tools/list
```

#### 2. Test All Tools (Automated Suite)
```bash
curl -X POST http://localhost:8100/api/v1/chat/test/tools/test-all \
  -H "Content-Type: application/json" \
  -d '{
    "phone_number": "+31612345678",
    "church_id": 1
  }'
```

#### 3. Validate Tool Call
```bash
curl -X POST http://localhost:8100/api/v1/chat/test/tools/validate \
  -H "Content-Type: application/json" \
  -d '{
    "tool": "manage_user",
    "arguments": {
      "name": "Jan",
      "age": 35,
      "target_group": "volwassenen"
    }
  }'
```

#### 4. Individual Tool Testing
```bash
curl -X POST http://localhost:8100/api/v1/chat/test/send \
  -H "Content-Type: application/json" \
  -d '{
    "phone_number": "+31612345678",
    "message": "Mijn naam is Jan en ik ben 35 jaar",
    "church_id": 1
  }'
```

#### 5. Conversation History
```bash
curl -X GET http://localhost:8100/api/v1/chat/test/conversation/%2B31612345678
```

#### 6. Reset Conversation
```bash
curl -X POST http://localhost:8100/api/v1/chat/test/reset/%2B31612345678
```

## Belangrijke Aandachtspunten

1. **Conversation Management**
   - Conversations blijven persistent tot nieuwe content
   - Member.activeSermonId tracked welke sermon actief is
   - Nieuwe conversation wordt ALLEEN aangemaakt bij nieuwe sermon

2. **Tool Call Flow**
   - OpenAI bepaalt proactief welke tools gebruikt worden
   - Tool responses worden teruggestuurd naar OpenAI
   - Recursieve tool calls worden ondersteund

3. **Queue Processing**
   - Default: sequentieel processing
   - Optioneel: parallel processing met pcntl fork
   - Scheduled content wordt automatisch naar queue verplaatst

4. **Error Handling**
   - 3x retry voor gefaalde content items
   - Error status met error message tracking
   - Comprehensive logging op alle levels

## Monitoring

Check de volgende endpoints voor monitoring:
- Health check: `GET /health`
- Queue statistics: `GET /api/v1/queue/stats`
- Member status: `GET /api/v1/members/{id}`

## Troubleshooting

### OpenAI Connection Issues
```bash
# Test OpenAI connection
docker exec -it church-chat-symfony php bin/console app:test-openai
```

### Signal Service Issues
```bash
# Check Signal service logs
docker logs church-signal-service

# Test Signal connection
curl http://localhost:8104/health
```

### Queue Processing Stuck
```bash
# Stop queue processor
touch /tmp/stop-queue-processor

# Check queue status
docker exec -it church-chat-symfony php bin/console app:queue-status
```

## Architecture Overview

```
Content Service → RabbitMQ → ContentReadyHandler
                                ↓
                          ContentStatus (queued)
                                ↓
                    ProcessContentQueueCommand
                                ↓
                    ContentDistributionService
                           ↙          ↘
                  OpenAI API        Signal API
                       ↓                 ↓
                 Conversation        Member Phone
```

## Next Steps

- [ ] Implement webhook voor Signal incoming messages
- [ ] Add metrics collection (Prometheus)
- [ ] Implement rate limiting voor OpenAI API
- [ ] Add admin dashboard voor member management
- [ ] Implement scheduled reflection questions