# OpenAI API Flow Documentatie

## 1. Content Generatie

De content microservice genereert content en uploadt deze naar de OpenAI Files API. Per kerk wordt er één actieve Vector Store aangemaakt met de huidige actieve content. De content microservice beheert dit proces.

### API Calls voor Vector Store

**Request:**
```http
POST https://api.openai.com/v1/vector_stores
```

**Body:**
```json
{
  "name": "Naam van de vector store",
  "file_ids": [
    "file-Hn5gRgMrUCXEhLMnpb6Hk5",
    "file-TjJPFrfvuWY7f7UUevUZha"
  ]
}
```

**Response:**
```json
{
  "id": "vs_68aca177bfe08191be9385c97a5f29d9",
  "object": "vector_store",
  "created_at": 1756143991,
  "name": "Nama van de Vector store",
  "description": null,
  "usage_bytes": 0,
  "file_counts": {
    "in_progress": 2,
    "completed": 0,
    "failed": 0,
    "cancelled": 0,
    "total": 2
  },
  "status": "in_progress",
  "expires_after": null,
  "expires_at": null,
  "last_active_at": 1756143991,
  "metadata": {}
}
```

## 2. Bericht Broadcast

Wanneer berichten worden verstuurd naar gebruikers, wordt er direct een Conversation aangemaakt bij OpenAI. Deze conversation bevat:

- Het verzonden bericht (voor context)
- De systeem prompt (gedifferentieerd op basis van doelgroep)

### API Calls voor Conversation

**Request:**
```http
POST https://api.openai.com/v1/conversations
```

**Body:**
```json
{
  "metadata": {
    "topic": "0624503133"
  },
  "items": [
    {
      "type": "message",
      "role": "assistant",
      "content": "Wow dat was een mooie preek! Was je erbij en wil je een samenvatting?"
    }
  ]
}
```

**Response:**
```json
{
  "id": "conv_68acc003ef808193a741eb3d155796d002e733cf862d1e13",
  "object": "conversation",
  "created_at": 1756151811,
  "metadata": {
    "topic": "0624503133"
  }
}
```

> **Belangrijk:** Het conversation ID moet worden opgeslagen totdat er een nieuwe preek beschikbaar komt.

## 3. Gebruiker Response API voor chatflow

Bij elke nieuwe communicatie van de gebruiker wordt dezelfde flow gevolgd:

1. Conversation ID meesturen
2. Als OpenAI aangeeft dat er een tool moet worden gebruikt, dan wordt dit uitgevoerd
4. De response moet dan worden teruggestuurd naar OpenAI, dan dan krijgen we weer nieuwe content.
3. Uiteindelijk: Response teruggeven naar de gebruiker.

Het AI-model bepaalt zelfstandig welke actie nodig is: zoeken in files, een tool gebruiken, of anders reageren.

Zie request_design.json, dit is de request die ik altijd wil opsturen. Deze prompt is namelijk goed getest.
Hierin moet natuurlijk het echte conversation id, de vector store id vanuit de church database en de input van de user.

### Request Example

```json
{
  "model": "gpt-5-nano",
  "conversation": "conv_68acc003ef808193a741eb3d155796d002e733cf862d1e13",
  "store": true,
  "input": [
    {
      "role": "user",
      "content": [
        {
          "type": "input_text",
          "text": "Waar ging de preek over?"
        }
      ]
    }
  ],
  "tools": [
    {
      "type": "file_search",
      "vector_store_ids": [
        "vs_68aca177bfe08191be9385c97a5f29d9"
      ]
    },
    {
      "type": "function",
      "name": "update_user",
      "description": "Sla gedeelde persoonlijke gegevens (leeftijd en/of naam) van de gebruiker op.",
      "strict": false,
      "parameters": {
        "type": "object",
        "properties": {
          "age": {
            "type": "integer",
            "min": 5,
            "max": 100,
            "description": "The age of the user"
          },
          "name": {
            "type": "string",
            "description": "The first name of the user"
          }
        },
        "required": [
          "name"
        ],
        "additionalProperties": false
      }
    }
  ],
  "tool_choice": "auto"
}
```

### Response Example

```json
{
  "id": "resp_68acc1abbaac8193b4a7300d0ca2016402e733cf862d1e13",
  "object": "response",
  "created_at": 1756152235,
  "status": "completed",
  "background": false,
  "conversation": {
    "id": "conv_68acc003ef808193a741eb3d155796d002e733cf862d1e13"
  },
  "error": null,
  "incomplete_details": null,
  "instructions": null,
  "max_output_tokens": null,
  "max_tool_calls": null,
  "model": "gpt-5-nano-2025-08-07",
  "output": [
    {
      "id": "rs_68acc1ac8db08193a977149a7c3e3c1f02e733cf862d1e13",
      "type": "reasoning",
      "summary": []
    },
    {
      "id": "fs_68acc1aef3948193aee21913d3778f2d02e733cf862d1e13",
      "type": "file_search_call",
      "status": "completed",
      "queries": [
        "Waar ging de preek over?",
        "preek deze week samenvatting",
        "kerk preek samenvatting",
        "dominant thema preek",
        "Wat was het onderwerp van de preek?"
      ],
      "results": null
    },
    {
      "id": "rs_68acc1b188408193930228563415806b02e733cf862d1e13",
      "type": "reasoning",
      "summary": []
    },
    {
      "id": "msg_68acc1b6c2ec8193a72955dc1fd3014002e733cf862d1e13",
      "type": "message",
      "status": "completed",
      "content": [
        {
          "type": "output_text",
          "annotations": [
            {
              "type": "file_citation",
              "file_id": "file-Hn5gRgMrUCXEhLMnpb6Hk5",
              "filename": "sermon_0198e253-c66d-71db-8bb2-1773412aa7a6_sermon_20250825.txt",
              "index": 458
            },
            {
              "type": "file_citation",
              "file_id": "file-Hn5gRgMrUCXEhLMnpb6Hk5",
              "filename": "sermon_0198e253-c66d-71db-8bb2-1773412aa7a6_sermon_20250825.txt",
              "index": 459
            },
            {
              "type": "file_citation",
              "file_id": "file-TjJPFrfvuWY7f7UUevUZha",
              "filename": "sermon_0198e253-c66d-71db-8bb2-1773412aa7a6_samenvatting_verdieping2_20250825.txt",
              "index": 689
            },
            {
              "type": "file_citation",
              "file_id": "file-Hn5gRgMrUCXEhLMnpb6Hk5",
              "filename": "sermon_0198e253-c66d-71db-8bb2-1773412aa7a6_sermon_20250825.txt",
              "index": 900
            },
            {
              "type": "file_citation",
              "file_id": "file-Hn5gRgMrUCXEhLMnpb6Hk5",
              "filename": "sermon_0198e253-c66d-71db-8bb2-1773412aa7a6_sermon_20250825.txt",
              "index": 901
            },
            {
              "type": "file_citation",
              "file_id": "file-Hn5gRgMrUCXEhLMnpb6Hk5",
              "filename": "sermon_0198e253-c66d-71db-8bb2-1773412aa7a6_sermon_20250825.txt",
              "index": 1091
            },
            {
              "type": "file_citation",
              "file_id": "file-Hn5gRgMrUCXEhLMnpb6Hk5",
              "filename": "sermon_0198e253-c66d-71db-8bb2-1773412aa7a6_sermon_20250825.txt",
              "index": 1249
            }
          ],
          "logprobs": [],
          "text": "Kort samengevat: de preek draait om Mephibosjet en de uitnodiging aan Gods tafel als metafoor voor ons geloofspad.\n\n- Kernverhaal: David zoekt nog iemand uit Saul's familie en roept Mephibosjet, die verlamd en in Lodabar leeft, naar het hof. Daar wordt hij aan tafel opgenomen als een van de koningszonen, waardoor zijn identiteit en waardigheid hersteld worden. Dit komt uit 2 Samuel 9 en het bijbehorende verhaal over het verbond tussen David en Jonathan.  \n\n- De betekenis van het verbond: de preek benadrukt dat Jezus' uitnodiging aan onze tafel gebaseerd is op het verbond dat God heeft gesloten (door Jezus' bloed). Het gaat niet om verdiensten, maar om Gods trouw aan Zijn belofte. \n\n- De tafel als plaats van genade, herstel en gemeenschap: aan de tafel gebeurt herstel van identiteit, vergeving en groei in genade. Het is een dagelijkse, voortdurende uitnodiging, geen \"part-time\" afspraak.  \n\n- Dagelijkse werkelijkheid: de preek moedigt aan om dagelijks tijd met God te nemen (gebed, Bijbel lezen, gemeenschap), omdat de uitnodiging aan elke dag geldig is en ons leven vernieuwt. \n\n- Concreet beeld voor onszelf: wij worden gezien als zonen en dochters van de Koning; aan tafel zitten betekent erkenning, aanwezigheid en zegen ontvangen. \n\nAls je wilt, kan ik er een korte, praktische samenvatting van maken met aandachtspunten voor deze week."
        }
      ],
      "role": "assistant"
    }
  ],
  "parallel_tool_calls": true,
  "previous_response_id": null,
  "prompt_cache_key": null,
  "reasoning": {
    "effort": "medium",
    "summary": null
  },
  "safety_identifier": null,
  "service_tier": "default",
  "store": true,
  "temperature": 1,
  "text": {
    "format": {
      "type": "text"
    },
    "verbosity": "medium"
  },
  "tool_choice": "auto",
  "tools": [
    {
      "type": "file_search",
      "filters": null,
      "max_num_results": 20,
      "ranking_options": {
        "ranker": "auto",
        "score_threshold": 0
      },
      "vector_store_ids": [
        "vs_68aca177bfe08191be9385c97a5f29d9"
      ]
    },
    {
      "type": "function",
      "description": "Sla gedeelde persoonlijke gegevens (leeftijd en/of naam) van de gebruiker op.",
      "name": "update_user",
      "parameters": {
        "type": "object",
        "properties": {
          "age": {
            "type": "integer",
            "min": 5,
            "max": 100,
            "description": "The age of the user"
          },
          "name": {
            "type": "string",
            "description": "The first name of the user"
          }
        },
        "required": [
          "name"
        ],
        "additionalProperties": false
      },
      "strict": false
    }
  ],
  "top_logprobs": 0,
  "top_p": 1,
  "truncation": "disabled",
  "usage": {
    "input_tokens": 19189,
    "input_tokens_details": {
      "cached_tokens": 2304
    },
    "output_tokens": 2099,
    "output_tokens_details": {
      "reasoning_tokens": 1664
    },
    "total_tokens": 21288
  },
  "user": null,
  "metadata": {}
}
```

## 5. Tool Calling Flow

Wanneer de AI een tool moet gebruiken (bijvoorbeeld het opslaan van gebruikersgegevens), volgt er een twee-staps proces:

### Stap 1: Initial Tool Call Request

```json
{
  "model": "gpt-5-nano",
  "conversation": "conv_68acc003ef808193a741eb3d155796d002e733cf862d1e13",
  "store": true,
  "instructions": "Je bent een assistent die preken samenvat. Als de vraag mogelijk over de preek van deze week gaat, gebruik dan file_search op de meegegeven vector store. Vertel expliciet wanneer je tools gebruikt. Wees ontspannen. Je wordt gebruikt op Signal. BELANGRIJK: Als de gebruiker leeftijd of naam deelt, roep ALTIJD de tool 'update_user' aan en bevestig dat het is opgeslagen.",
  "input": [
    {
      "role": "user",
      "content": [
        {
          "type": "input_text",
          "text": "ik ben renko en ben 39 jaar oud"
        }
      ]
    }
  ],
  "tools": [
    {
      "type": "file_search",
      "vector_store_ids": [
        "vs_68aca177bfe08191be9385c97a5f29d9"
      ]
    },
    {
      "type": "function",
      "name": "update_user",
      "description": "Sla gedeelde persoonlijke gegevens (leeftijd en/of naam) van de gebruiker op.",
      "strict": false,
      "parameters": {
        "type": "object",
        "properties": {
          "age": {
            "type": "integer",
            "min": 5,
            "max": 100,
            "description": "The age of the user"
          },
          "name": {
            "type": "string",
            "description": "The first name of the user"
          }
        },
        "required": ["name"],
        "additionalProperties": false
      }
    }
  ],
  "tool_choice": "auto"
}
```

### Stap 1: Response met Tool Call

```json
{
  "id": "resp_68acb6cdb27c8195b719cc06ab53fc070c5a772cb7744d5c",
  "object": "response",
  "created_at": 1756149453,
  "status": "completed",
  "background": false,
  "conversation": {
    "id": "conv_68acb64ac2f48195b24726a9da6b44690c5a772cb7744d5c"
  },
  "error": null,
  "incomplete_details": null,
  "instructions": "Je bent een assistent die preken samenvat. Als de vraag mogelijk over de preek van deze week gaat, gebruik dan file_search op de meegegeven vector store. Vertel expliciet wanneer je tools gebruikt. Wees ontspannen. Je wordt gebruikt op Signal. BELANGRIJK: Als de gebruiker leeftijd of naam deelt, roep ALTIJD de tool 'update_user' aan en bevestig dat het is opgeslagen.",
  "max_output_tokens": null,
  "max_tool_calls": null,
  "model": "gpt-5-nano-2025-08-07",
  "output": [
    {
      "id": "rs_68acb6ce94348195a2017b16c843e3e40c5a772cb7744d5c",
      "type": "reasoning",
      "summary": []
    },
    {
      "id": "fc_68acb6d0d338819584efab576a6b997f0c5a772cb7744d5c",
      "type": "function_call",
      "status": "completed",
      "arguments": "{\"age\":39,\"name\":\"Renko\"}",
      "call_id": "call_5BoDV6vZmmV4O6GO02pWRHWl",
      "name": "update_user"
    }
  ],
  "parallel_tool_calls": true,
  "previous_response_id": null,
  "prompt_cache_key": null,
  "reasoning": {
    "effort": "medium",
    "summary": null
  },
  "safety_identifier": null,
  "service_tier": "default",
  "store": true,
  "temperature": 1,
  "text": {
    "format": {
      "type": "text"
    },
    "verbosity": "medium"
  },
  "tool_choice": "auto",
  "tools": [
    {
      "type": "file_search",
      "filters": null,
      "max_num_results": 20,
      "ranking_options": {
        "ranker": "auto",
        "score_threshold": 0
      },
      "vector_store_ids": [
        "vs_68aca177bfe08191be9385c97a5f29d9"
      ]
    },
    {
      "type": "function",
      "description": "Sla gedeelde persoonlijke gegevens (leeftijd en/of naam) van de gebruiker op.",
      "name": "update_user",
      "parameters": {
        "type": "object",
        "properties": {
          "age": {
            "type": "integer",
            "min": 5,
            "max": 100,
            "description": "The age of the user"
          },
          "name": {
            "type": "string",
            "description": "The first name of the user"
          }
        },
        "required": [
          "name"
        ],
        "additionalProperties": false
      },
      "strict": false
    }
  ],
  "top_logprobs": 0,
  "top_p": 1,
  "truncation": "disabled",
  "usage": {
    "input_tokens": 1566,
    "input_tokens_details": {
      "cached_tokens": 0
    },
    "output_tokens": 537,
    "output_tokens_details": {
      "reasoning_tokens": 512
    },
    "total_tokens": 2103
  },
  "user": null,
  "metadata": {}
}
```

### Stap 2: Tool Output Terugsturen

Na het uitvoeren van de tool moet het resultaat teruggestuurd worden:

```json
{
  "model": "gpt-5-nano",
  "conversation": "conv_68acc003ef808193a741eb3d155796d002e733cf862d1e13",
  "store": true,
  "input": [
    {
      "type": "function_call_output",
      "call_id": "call_KoCDVinaVPYlWfnluehMsBsi",
      "output": "{\"success\": true, \"message\": \"Gebruiker opgeslagen\"}"
    }
  ],
  "tools": [
    {
      "type": "file_search",
      "vector_store_ids": [
        "vs_68aca177bfe08191be9385c97a5f29d9"
      ]
    },
    {
      "type": "function",
      "name": "update_user",
      "description": "Sla gedeelde persoonlijke gegevens (leeftijd en/of naam) van de gebruiker op.",
      "strict": false,
      "parameters": {
        "type": "object",
        "properties": {
          "age": {
            "type": "integer",
            "min": 5,
            "max": 100,
            "description": "The age of the user"
          },
          "name": {
            "type": "string",
            "description": "The first name of the user"
          }
        },
        "required": ["name"],
        "additionalProperties": false
      }
    }
  ],
  "tool_choice": "auto"
}
```

### Stap 2: Final Response

```json
{
  "id": "resp_68acc16b94548193ac3b5e260ed7aa7402e733cf862d1e13",
  "object": "response",
  "created_at": 1756152171,
  "status": "completed",
  "background": false,
  "conversation": {
    "id": "conv_68acc003ef808193a741eb3d155796d002e733cf862d1e13"
  },
  "error": null,
  "incomplete_details": null,
  "instructions": null,
  "max_output_tokens": null,
  "max_tool_calls": null,
  "model": "gpt-5-nano-2025-08-07",
  "output": [
    {
      "id": "rs_68acc17009f48193a28e55310ac6469202e733cf862d1e13",
      "type": "reasoning",
      "summary": []
    },
    {
      "id": "msg_68acc1711eb8819386342efca31858cd02e733cf862d1e13",
      "type": "message",
      "status": "completed",
      "content": [
        {
          "type": "output_text",
          "annotations": [],
          "logprobs": [],
          "text": "Bedankt Renko! Je naam en leeftijd zijn opgeslagen: Renko, 39 jaar.\n\nWil je dat ik de preek van deze week samenvat? Als je ja zegt, haal ik de preek uit onze bestanden en geef ik een korte samenvatting (met eventuele aandachtspunten die jij wilt)."
        }
      ],
      "role": "assistant"
    }
  ],
  "parallel_tool_calls": true,
  "previous_response_id": null,
  "prompt_cache_key": null,
  "reasoning": {
    "effort": "medium",
    "summary": null
  },
  "safety_identifier": null,
  "service_tier": "default",
  "store": true,
  "temperature": 1,
  "text": {
    "format": {
      "type": "text"
    },
    "verbosity": "medium"
  },
  "tool_choice": "auto",
  "tools": [
    {
      "type": "file_search",
      "filters": null,
      "max_num_results": 20,
      "ranking_options": {
        "ranker": "auto",
        "score_threshold": 0
      },
      "vector_store_ids": [
        "vs_68aca177bfe08191be9385c97a5f29d9"
      ]
    },
    {
      "type": "function",
      "description": "Sla gedeelde persoonlijke gegevens (leeftijd en/of naam) van de gebruiker op.",
      "name": "update_user",
      "parameters": {
        "type": "object",
        "properties": {
          "age": {
            "type": "integer",
            "min": 5,
            "max": 100,
            "description": "The age of the user"
          },
          "name": {
            "type": "string",
            "description": "The first name of the user"
          }
        },
        "required": [
          "name"
        ],
        "additionalProperties": false
      },
      "strict": false
    }
  ],
  "top_logprobs": 0,
  "top_p": 1,
  "truncation": "disabled",
  "usage": {
    "input_tokens": 2403,
    "input_tokens_details": {
      "cached_tokens": 1280
    },
    "output_tokens": 262,
    "output_tokens_details": {
      "reasoning_tokens": 192
    },
    "total_tokens": 2665
  },
  "user": null,
  "metadata": {}
}
```

## Samenvatting van de Flow

1. **Content wordt geüpload** naar OpenAI Files API en een Vector Store wordt aangemaakt
2. **Bij broadcast** wordt een Conversation gestart met het uitgaande bericht
3. **Bij gebruikersinput** wordt de conversation gebruikt met alle benodigde tools
4. **Het AI model** bepaalt zelf welke actie nodig is (file search, tool gebruik, etc.)
5. **Bij tool gebruik** volgt een twee-staps proces: tool call → tool execution → final response. Er kunnen diverse tool executions achter elkaar zijn
