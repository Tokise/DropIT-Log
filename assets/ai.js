// AI Assistant Integration JavaScript
const AI = {
    currentSession: null,
    chatContainer: null,
    
    // Initialize AI assistant
    init() {
        this.bindEvents();
        this.loadRecommendations();
    },
    
    // Start new chat session
    async startChatSession(context = {}) {
        try {
            const response = await Api.send('api/ai_assistant.php?action=chat', 'POST', {
                message: 'Hello, I need assistance with the logistics system.',
                context: context
            });
            
            this.currentSession = response.data.session_id;
            this.showChatInterface();
            this.addMessage('assistant', response.data.response);
            
            return this.currentSession;
        } catch (error) {
            console.error('Error starting chat session:', error);
            this.showError('Failed to start AI chat session');
        }
    },
    
    // Send message to AI
    async sendMessage(message, context = {}) {
        if (!message.trim()) return;
        
        // Add user message to chat
        this.addMessage('user', message);
        
        // Show typing indicator
        this.showTypingIndicator();
        
        try {
            const response = await Api.send('api/ai_assistant.php?action=chat', 'POST', {
                message: message,
                session_id: this.currentSession,
                context: context
            });
            
            // Remove typing indicator
            this.hideTypingIndicator();
            
            // Add AI response
            this.addMessage('assistant', response.data.response);
            
            // Handle any metadata (like recommendations)
            if (response.data.metadata) {
                this.handleMetadata(response.data.metadata);
            }
            
        } catch (error) {
            this.hideTypingIndicator();
            console.error('Error sending message:', error);
            this.addMessage('system', 'Sorry, I encountered an error. Please try again.');
        }
    },
    
    // Load AI recommendations
    async loadRecommendations(module = '') {
        try {
            const params = new URLSearchParams({
                module: module,
                status: 'pending'
            });
            
            const response = await Api.get(`api/ai_assistant.php?action=recommendations&${params}`);
            this.renderRecommendations(response.data.recommendations);
        } catch (error) {
            console.error('Error loading recommendations:', error);
        }
    },
    
    // Generate recommendations for current module
    async generateRecommendations(module, context = {}) {
        try {
            const response = await Api.send('api/ai_assistant.php?action=recommend', 'POST', {
                module: module,
                context: context
            });
            
            this.showSuccess(`Generated ${response.data.count} new recommendations`);
            this.loadRecommendations(module);
            
        } catch (error) {
            console.error('Error generating recommendations:', error);
            this.showError('Failed to generate recommendations');
        }
    },
    
    // Analyze data with AI
    async analyzeData(module, analysisType, parameters = {}) {
        try {
            const response = await Api.send('api/ai_assistant.php?action=analyze', 'POST', {
                module: module,
                analysis_type: analysisType,
                parameters: parameters
            });
            
            this.showAnalysisResults(response.data.analysis);
            
        } catch (error) {
            console.error('Error analyzing data:', error);
            this.showError('Failed to analyze data');
        }
    },
    
    // Accept recommendation
    async acceptRecommendation(recommendationId) {
        try {
            await Api.send('api/ai_assistant.php?action=accept_recommendation', 'PUT', {
                recommendation_id: recommendationId
            });
            
            this.showSuccess('Recommendation accepted');
            this.loadRecommendations();
            
        } catch (error) {
            console.error('Error accepting recommendation:', error);
            this.showError('Failed to accept recommendation');
        }
    },
    
    // Reject recommendation
    async rejectRecommendation(recommendationId) {
        try {
            await Api.send('api/ai_assistant.php?action=reject_recommendation', 'PUT', {
                recommendation_id: recommendationId
            });
            
            this.showSuccess('Recommendation rejected');
            this.loadRecommendations();
            
        } catch (error) {
            console.error('Error rejecting recommendation:', error);
            this.showError('Failed to reject recommendation');
        }
    },
    
    // Show chat interface
    showChatInterface() {
        const modal = document.getElementById('aiChatModal');
        if (modal) {
            new bootstrap.Modal(modal).show();
            this.chatContainer = document.getElementById('aiChatContainer');
            if (this.chatContainer) {
                this.chatContainer.innerHTML = '';
            }
        }
    },
    
    // Add message to chat
    addMessage(type, content) {
        if (!this.chatContainer) return;
        
        const messageDiv = document.createElement('div');
        messageDiv.className = `mb-3 d-flex ${type === 'user' ? 'justify-content-end' : 'justify-content-start'}`;
        
        const messageContent = document.createElement('div');
        messageContent.className = `p-3 rounded ${this.getMessageClass(type)}`;
        messageContent.style.maxWidth = '80%';
        
        if (type === 'user') {
            messageContent.innerHTML = `<strong>You:</strong><br>${this.escapeHtml(content)}`;
        } else if (type === 'assistant') {
            messageContent.innerHTML = `<strong>AI Assistant:</strong><br>${this.formatAIResponse(content)}`;
        } else {
            messageContent.innerHTML = `<em>${this.escapeHtml(content)}</em>`;
        }
        
        messageDiv.appendChild(messageContent);
        this.chatContainer.appendChild(messageDiv);
        
        // Scroll to bottom
        this.chatContainer.scrollTop = this.chatContainer.scrollHeight;
    },
    
    // Show typing indicator
    showTypingIndicator() {
        if (!this.chatContainer) return;
        
        const typingDiv = document.createElement('div');
        typingDiv.id = 'typingIndicator';
        typingDiv.className = 'mb-3 d-flex justify-content-start';
        typingDiv.innerHTML = `
            <div class="p-3 rounded bg-light text-muted">
                <i class="fas fa-circle-notch fa-spin me-2"></i>AI is thinking...
            </div>
        `;
        
        this.chatContainer.appendChild(typingDiv);
        this.chatContainer.scrollTop = this.chatContainer.scrollHeight;
    },
    
    // Hide typing indicator
    hideTypingIndicator() {
        const indicator = document.getElementById('typingIndicator');
        if (indicator) {
            indicator.remove();
        }
    },
    
    // Render recommendations
    renderRecommendations(recommendations) {
        const container = document.getElementById('aiRecommendationsContainer');
        if (!container) return;
        
        if (!recommendations || recommendations.length === 0) {
            container.innerHTML = '<div class="text-muted text-center py-3">No recommendations available</div>';
            return;
        }
        
        container.innerHTML = recommendations.map(rec => `
            <div class="card mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <h6 class="card-title">${rec.title}</h6>
                            <p class="card-text">${rec.description}</p>
                            <small class="text-muted">
                                <span class="badge bg-${this.getModuleColor(rec.module)}">${rec.module.toUpperCase()}</span>
                                Confidence: ${(rec.confidence_score * 100).toFixed(1)}%
                            </small>
                        </div>
                        <div class="ms-3">
                            <button class="btn btn-sm btn-success me-1" onclick="AI.acceptRecommendation(${rec.id})" title="Accept">
                                <i class="fas fa-check"></i>
                            </button>
                            <button class="btn btn-sm btn-danger" onclick="AI.rejectRecommendation(${rec.id})" title="Reject">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `).join('');
    },
    
    // Show analysis results
    showAnalysisResults(analysis) {
        const modal = document.getElementById('aiAnalysisModal');
        if (!modal) return;
        
        const container = document.getElementById('aiAnalysisContent');
        if (container) {
            container.innerHTML = `
                <div class="alert alert-info">
                    <h5><i class="fas fa-chart-line me-2"></i>AI Analysis Results</h5>
                    <div class="mt-3">${this.formatAIResponse(analysis)}</div>
                </div>
            `;
        }
        
        new bootstrap.Modal(modal).show();
    },
    
    // Handle metadata from AI responses
    handleMetadata(metadata) {
        if (metadata.recommendations) {
            this.loadRecommendations();
        }
        
        if (metadata.actions) {
            // Handle suggested actions
            metadata.actions.forEach(action => {
                this.showActionSuggestion(action);
            });
        }
    },
    
    // Show action suggestion
    showActionSuggestion(action) {
        // This could show a toast or notification with suggested actions
        console.log('Suggested action:', action);
    },
    
    // Bind event listeners
    bindEvents() {
        // Chat form
        const chatForm = document.getElementById('aiChatForm');
        if (chatForm) {
            chatForm.addEventListener('submit', (e) => {
                e.preventDefault();
                const messageInput = document.getElementById('aiMessageInput');
                if (messageInput && messageInput.value.trim()) {
                    this.sendMessage(messageInput.value.trim());
                    messageInput.value = '';
                }
            });
        }
        
        // AI assistant button
        const aiButton = document.getElementById('aiAssistantBtn');
        if (aiButton) {
            aiButton.addEventListener('click', () => {
                this.startChatSession({
                    module: this.getCurrentModule(),
                    page: window.location.pathname
                });
            });
        }
        
        // Generate recommendations button
        const generateBtn = document.getElementById('generateRecommendationsBtn');
        if (generateBtn) {
            generateBtn.addEventListener('click', () => {
                this.generateRecommendations(this.getCurrentModule());
            });
        }
        
        // Analysis buttons
        document.addEventListener('click', (e) => {
            if (e.target.matches('[data-ai-analyze]')) {
                const analysisType = e.target.getAttribute('data-ai-analyze');
                const module = this.getCurrentModule();
                this.analyzeData(module, analysisType);
            }
        });
    },
    
    // Utility functions
    getCurrentModule() {
        const path = window.location.pathname;
        if (path.includes('sws.php')) return 'sws';
        if (path.includes('psm.php')) return 'psm';
        if (path.includes('plt.php')) return 'plt';
        if (path.includes('alms.php')) return 'alms';
        if (path.includes('dtrs.php')) return 'dtlrs';
        return 'general';
    },
    
    getMessageClass(type) {
        const classes = {
            'user': 'bg-primary text-white',
            'assistant': 'bg-light border',
            'system': 'bg-warning text-dark'
        };
        return classes[type] || 'bg-light';
    },
    
    getModuleColor(module) {
        const colors = {
            'sws': 'primary',
            'psm': 'success',
            'plt': 'info',
            'alms': 'warning',
            'dtlrs': 'secondary'
        };
        return colors[module] || 'secondary';
    },
    
    formatAIResponse(content) {
        // Basic formatting for AI responses
        return content
            .replace(/\n/g, '<br>')
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.*?)\*/g, '<em>$1</em>');
    },
    
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    },
    
    showSuccess(message) {
        // You can implement toast notifications or use your preferred notification system
        console.log('Success:', message);
    },
    
    showError(message) {
        // You can implement toast notifications or use your preferred notification system
        console.error('Error:', message);
    }
};

// Global AI helper functions
window.AI_HELPER = {
    // Quick AI assistance for current context
    askAI: (question) => {
        if (!AI.currentSession) {
            AI.startChatSession().then(() => {
                AI.sendMessage(question);
            });
        } else {
            AI.sendMessage(question);
        }
    },
    
    // Get recommendations for current module
    getRecommendations: () => {
        AI.generateRecommendations(AI.getCurrentModule());
    },
    
    // Analyze current data
    analyzeData: (type) => {
        AI.analyzeData(AI.getCurrentModule(), type);
    }
};

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    AI.init();
    
    // Add AI assistant button to all pages if not exists
    if (!document.getElementById('aiAssistantBtn')) {
        const aiBtn = document.createElement('button');
        aiBtn.id = 'aiAssistantBtn';
        aiBtn.className = 'btn btn-primary position-fixed';
        aiBtn.style.cssText = 'bottom: 20px; right: 20px; z-index: 1000; border-radius: 50%; width: 60px; height: 60px;';
        aiBtn.innerHTML = '<i class="fas fa-robot"></i>';
        aiBtn.title = 'AI Assistant';
        document.body.appendChild(aiBtn);
    }
});
