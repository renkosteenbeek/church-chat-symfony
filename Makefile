.PHONY: help start-all stop-all status-all health-all logs-all restart-all clean-all

help:
	@echo "Church Media Platform - Central Control"
	@echo "========================================"
	@echo "Service Management:"
	@echo "  make start-all       - Start all microservices in correct order"
	@echo "  make stop-all        - Stop all microservices"
	@echo "  make restart-all     - Restart all microservices"
	@echo "  make status-all      - Show status of all services"
	@echo "  make health-all      - Check health of all services"
	@echo "  make logs-all        - View logs from all services (use SERVICE=name for specific)"
	@echo "  make clean-all       - Stop and clean all services and volumes"
	@echo ""
	@echo "Individual Service Commands:"
	@echo "  Infrastructure: cd church-infrastructure && make help"
	@echo "  Signal:         cd church-signal-service && make help"
	@echo "  Chat:           cd church-chat-symfony && make help"
	@echo "  Content:        cd church-content-symfony && make help"
	@echo "  Whisper:        cd church-whisper-api && ./run.sh --help"

start-all:
	@echo "🚀 Starting Church Media Platform..."
	@echo "======================================"
	@echo ""
	@echo "📡 Step 1/5: Starting Infrastructure (RabbitMQ, MySQL)..."
	cd church-infrastructure && make up
	@echo "⏳ Waiting for infrastructure to be ready..."
	@sleep 5
	@echo ""
	@echo "📱 Step 2/5: Starting Signal Service..."
	cd church-signal-service && make up
	@echo "⏳ Waiting for Signal service to be ready..."
	@sleep 3
	@echo ""
	@echo "💬 Step 3/5: Starting Chat Service..."
	cd church-chat-symfony && make up
	@echo "⏳ Waiting for Chat service to be ready..."
	@sleep 3
	@echo ""
	@echo "📝 Step 4/5: Starting Content Service..."
	cd church-content-symfony && make up
	@echo "⏳ Waiting for Content service to be ready..."
	@sleep 5
	@echo ""
	@echo "🎤 Step 5/5: Starting Whisper API (native macOS)..."
	cd church-whisper-api && ./run.sh
	@echo ""
	@echo "✅ All services started successfully!"
	@echo ""
	@echo "🌐 Service URLs:"
	@echo "  Infrastructure Dashboard: http://localhost:8200"
	@echo "  Chat Service API:         http://localhost:8100/docs"
	@echo "  Content Service API:      http://localhost:8101/docs"
	@echo "  Whisper API:              http://localhost:8103/docs"
	@echo "  Signal API:               http://localhost:8180/v1/about"
	@echo "  RabbitMQ Management:      http://localhost:15673"
	@echo ""
	@echo "📋 Quick health check..."
	@$(MAKE) health-all

stop-all:
	@echo "🛑 Stopping Church Media Platform..."
	@echo "====================================="
	@echo ""
	@echo "🎤 Step 1/5: Stopping Whisper API..."
	cd church-whisper-api && ./stop.sh || echo "Whisper API may not be running"
	@echo ""
	@echo "📝 Step 2/5: Stopping Content Service..."
	cd church-content-symfony && make down
	@echo ""
	@echo "💬 Step 3/5: Stopping Chat Service..."
	cd church-chat-symfony && make down
	@echo ""
	@echo "📱 Step 4/5: Stopping Signal Service..."
	cd church-signal-service && make down
	@echo ""
	@echo "📡 Step 5/5: Stopping Infrastructure..."
	cd church-infrastructure && make down
	@echo ""
	@echo "✅ All services stopped successfully!"

restart-all: stop-all start-all

status-all:
	@echo "📊 Church Media Platform Status"
	@echo "==============================="
	@echo ""
	@echo "📡 Infrastructure:"
	@cd church-infrastructure && make status || echo "Infrastructure not responding"
	@echo ""
	@echo "📱 Signal Service:"
	@cd church-signal-service && make status || echo "Signal service not responding"
	@echo ""
	@echo "💬 Chat Service:"
	@cd church-chat-symfony && docker-compose ps || echo "Chat service not responding"
	@echo ""
	@echo "📝 Content Service:"
	@cd church-content-symfony && docker-compose ps || echo "Content service not responding"
	@echo ""
	@echo "🎤 Whisper API:"
	@if lsof -ti :8103 > /dev/null 2>&1; then \
		echo "✅ Whisper API running (PID: $$(lsof -ti :8103))"; \
	else \
		echo "❌ Whisper API not running"; \
	fi

health-all:
	@echo "🏥 Church Media Platform Health Check"
	@echo "======================================"
	@echo ""
	@echo "📡 Infrastructure Health:"
	@cd church-infrastructure && make health || echo "Infrastructure health check failed"
	@echo ""
	@echo "📱 Signal Service Health:"
	@cd church-signal-service && make health || echo "Signal service health check failed"
	@echo ""
	@echo "💬 Chat Service Health:"
	@curl -s http://localhost:8100/health && echo " ✅ Chat Service" || echo "❌ Chat Service not responding"
	@echo ""
	@echo "📝 Content Service Health:"
	@curl -s http://localhost:8101/monitoring/health && echo " ✅ Content Service" || echo "❌ Content Service not responding"
	@echo ""
	@echo "🎤 Whisper API Health:"
	@curl -s http://localhost:8103/health && echo " ✅ Whisper API" || echo "❌ Whisper API not responding"

logs-all:
	@if [ -z "$(SERVICE)" ]; then \
		echo "📜 Viewing logs from all services..."; \
		echo "Use Ctrl+C to stop, or specify SERVICE=<name> for specific service"; \
		echo "Available services: infrastructure, signal, chat, content, whisper"; \
		echo ""; \
		echo "🔄 Starting log aggregation..."; \
		( \
			cd church-infrastructure && docker-compose logs -f --tail=10 & \
			cd church-signal-service && docker-compose logs -f --tail=10 & \
			cd church-chat-symfony && docker-compose logs -f --tail=10 & \
			cd church-content-symfony && docker-compose logs -f --tail=10 & \
			wait \
		); \
	else \
		if [ "$(SERVICE)" = "infrastructure" ]; then \
			cd church-infrastructure && make logs; \
		elif [ "$(SERVICE)" = "signal" ]; then \
			cd church-signal-service && make logs; \
		elif [ "$(SERVICE)" = "chat" ]; then \
			cd church-chat-symfony && make logs; \
		elif [ "$(SERVICE)" = "content" ]; then \
			cd church-content-symfony && make logs; \
		elif [ "$(SERVICE)" = "whisper" ]; then \
			cd church-whisper-api && tail -f logs/whisper-api.log 2>/dev/null || echo "Whisper logs not available"; \
		else \
			echo "❌ Unknown service: $(SERVICE)"; \
			echo "Available services: infrastructure, signal, chat, content, whisper"; \
		fi; \
	fi

clean-all:
	@echo "🧹 Cleaning Church Media Platform..."
	@echo "===================================="
	@echo ""
	@echo "⚠️  This will remove all containers, volumes, and data!"
	@echo "📱 Cleaning Signal Service..."
	@cd church-signal-service && make clean || echo "Signal service cleanup failed"
	@echo ""
	@echo "💬 Cleaning Chat Service..."
	@cd church-chat-symfony && docker-compose down --volumes --remove-orphans || echo "Chat service cleanup failed"
	@echo ""
	@echo "📝 Cleaning Content Service..."
	@cd church-content-symfony && docker-compose down --volumes --remove-orphans || echo "Content service cleanup failed"
	@echo ""
	@echo "📡 Cleaning Infrastructure..."
	@cd church-infrastructure && make clean || echo "Infrastructure cleanup failed"
	@echo ""
	@echo "🎤 Cleaning Whisper API..."
	@cd church-whisper-api && ./stop.sh || echo "Whisper API may not be running"
	@echo ""
	@echo "🗑️  Pruning Docker system..."
	@docker system prune -f
	@echo ""
	@echo "✅ Cleanup completed!"
