<div class="hapvida-reports-wrapper">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .hapvida-reports-wrapper * {
            box-sizing: border-box;
        }

        .hapvida-reports-wrapper {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 80vh;
            /* Changed from 100vh to avoid full screen takeover */
            padding: 20px;
            border-radius: 8px;
            /* Added border radius for better integration */
        }

        .login-container {
            max-width: 400px;
            margin: 100px auto;
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }

        .login-container h2 {
            color: #333;
            margin-bottom: 30px;
            text-align: center;
        }

        .login-form .form-group {
            margin-bottom: 20px;
        }

        .login-form label {
            display: block;
            margin-bottom: 5px;
            color: #666;
            font-weight: 500;
        }

        .login-form input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e1e1e1;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        .login-form input:focus {
            outline: none;
            border-color: #667eea;
        }

        .login-form button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
        }

        .login-form button:hover {
            transform: translateY(-2px);
        }

        .login-form button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .error-message {
            background: #fee;
            color: #c33;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: none;
        }

        .error-message.show {
            display: block;
        }

        .dashboard-container {
            display: none;
            max-width: 1400px;
            margin: 0 auto;
        }

        .dashboard-container.active {
            display: block;
        }

        .dashboard-header {
            background: white;
            padding: 20px 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .dashboard-header h1 {
            color: #333;
            font-size: 28px;
        }

        .logout-btn {
            padding: 10px 20px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
        }

        .logout-btn:hover {
            background: #c82333;
        }

        .filters-section {
            background: white;
            padding: 20px 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .filters-row {
            display: flex;
            gap: 20px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 5px;
            color: #666;
            font-weight: 500;
            font-size: 14px;
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 10px;
            border: 2px solid #e1e1e1;
            border-radius: 5px;
            font-size: 14px;
        }

        .filter-btn {
            padding: 10px 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            height: 42px;
        }

        .filter-btn:hover {
            opacity: 0.9;
        }

        .export-btn {
            padding: 10px 30px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            height: 42px;
        }

        .export-btn:hover {
            background: #218838;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .stat-card h3 {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
            font-weight: 500;
        }

        .stat-card .stat-value {
            font-size: 36px;
            font-weight: 700;
            color: #333;
            margin-bottom: 5px;
        }

        .stat-card .stat-label {
            color: #999;
            font-size: 12px;
        }

        .stat-card.primary .stat-value {
            color: #667eea;
        }

        .stat-card.success .stat-value {
            color: #28a745;
        }

        .stat-card.warning .stat-value {
            color: #ffc107;
        }

        .stat-card.danger .stat-value {
            color: #dc3545;
        }

        .chart-section {
            background: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .chart-section h2 {
            color: #333;
            margin-bottom: 20px;
            font-size: 20px;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 30px;
            margin-bottom: 30px;
        }

        .table-section {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow-x: auto;
        }

        .table-section h2 {
            color: #333;
            margin-bottom: 20px;
            font-size: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        table th,
        table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e1e1e1;
        }

        table th {
            background: #f8f9fa;
            color: #333;
            font-weight: 600;
            font-size: 14px;
        }

        table td {
            color: #666;
            font-size: 14px;
        }

        table tbody tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .status-badge.confirmado {
            background: #d4edda;
            color: #155724;
        }

        .status-badge.aguardando {
            background: #fff3cd;
            color: #856404;
        }

        .status-badge.expirado {
            background: #f8d7da;
            color: #721c24;
        }

        .status-badge.redistribuido {
            background: #d1ecf1;
            color: #0c5460;
        }

        .loading {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .quick-filters {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }

        .quick-filter-btn {
            padding: 8px 16px;
            background: #f8f9fa;
            border: 2px solid #e1e1e1;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            color: #666;
            transition: all 0.3s;
        }

        .quick-filter-btn:hover,
        .quick-filter-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: #667eea;
        }

        @media (max-width: 768px) {
            .dashboard-header {
                flex-direction: column;
                gap: 15px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .charts-grid {
                grid-template-columns: 1fr;
            }

            .filters-row {
                flex-direction: column;
            }

            .filter-group {
                width: 100%;
            }
        }
    </style>
    </head>

    <body>
        <!-- Tela de Login -->
        <div id="loginContainer" class="login-container"
            style="display: <?php echo (isset($authenticated) && $authenticated) ? 'none' : 'block'; ?>">
            <h2>Relatórios de Leads Hapvida</h2>
            <div id="errorMessage" class="error-message"></div>
            <form id="loginForm" class="login-form">
                <div class="form-group">
                    <label for="username">Usuário</label>
                    <input type="text" id="username" name="username" required>
                </div>
                <div class="form-group">
                    <label for="password">Senha</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" id="loginBtn">Entrar</button>
            </form>
        </div>

        <!-- Dashboard -->
        <div id="dashboardContainer"
            class="dashboard-container<?php echo (isset($authenticated) && $authenticated) ? ' active' : ''; ?>">
            <!-- Header -->
            <div class="dashboard-header">
                <h1>📊 Relatórios de Leads Hapvida</h1>
                <button id="logoutBtn" class="logout-btn">Sair</button>
            </div>

            <!-- Filtros -->
            <div class="filters-section">
                <div class="quick-filters">
                    <button class="quick-filter-btn active" data-period="29">Últimos 30 dias</button>
                    <button class="quick-filter-btn" data-period="6">Últimos 7 dias</button>
                    <button class="quick-filter-btn" data-period="0">Hoje</button>
                    <button class="quick-filter-btn" data-period="this-month">Este mês</button>
                </div>
                <div class="filters-row">
                    <div class="filter-group">
                        <label for="startDate">Data Início</label>
                        <input type="date" id="startDate" name="startDate">
                    </div>
                    <div class="filter-group">
                        <label for="endDate">Data Fim</label>
                        <input type="date" id="endDate" name="endDate">
                    </div>
                    <div class="filter-group">
                        <label for="vendorFilter">Vendedor</label>
                        <select id="vendorFilter" name="vendorFilter">
                            <option value="">Todos os vendedores</option>
                        </select>
                    </div>
                    <button id="filterBtn" class="filter-btn">Filtrar</button>
                    <button id="exportBtn" class="export-btn">Exportar CSV</button>
                </div>
            </div>

            <!-- Cards de Estatísticas -->
            <div class="stats-grid" id="statsGrid">
                <div class="stat-card primary">
                    <h3>Total de Leads</h3>
                    <div class="stat-value" id="totalLeads">0</div>
                    <div class="stat-label">No período selecionado</div>
                </div>
                <div class="stat-card success">
                    <h3>Valor Total</h3>
                    <div class="stat-value" id="totalValue" style="font-size: 28px;">R$ 0,00</div>
                    <div class="stat-label">Leads × R$ 12,00</div>
                </div>
                <div class="stat-card warning">
                    <h3>Média por Dia</h3>
                    <div class="stat-value" id="avgPerDay">0</div>
                    <div class="stat-label">No período selecionado</div>
                </div>
                <div class="stat-card danger">
                    <h3>Maior Pico</h3>
                    <div class="stat-value" id="peakDay">0</div>
                    <div class="stat-label" id="peakDayLabel">Nenhum lead no período</div>
                </div>
            </div>

            <!-- Gráficos -->
            <div class="chart-section">
                <h2>Timeline de Leads</h2>
                <canvas id="timelineChart"></canvas>
            </div>

            <div class="charts-grid">
                <div class="chart-section">
                    <h2>Top 10 Cidades</h2>
                    <canvas id="citiesChart"></canvas>
                </div>
                <div class="chart-section">
                    <h2>Leads por Tipo de Plano</h2>
                    <canvas id="planChart"></canvas>
                </div>
            </div>

            <div class="chart-section">
                <h2>Performance dos Vendedores</h2>
                <canvas id="vendorsChart"></canvas>
            </div>

            <!-- Tabela de Leads Recentes -->
            <div class="table-section">
                <h2>Leads Recentes</h2>
                <div id="tableLoading" class="loading">
                    <div class="spinner"></div>
                    <p>Carregando dados...</p>
                </div>
                <table id="leadsTable" style="display: none;">
                    <thead>
                        <tr>
                            <th>Data/Hora</th>
                            <th>Nome</th>
                            <th>Telefone</th>
                            <th>Cidade</th>
                            <th>Plano</th>
                            <th>Qtd Pessoas</th>
                            <th>Vendedor</th>
                            <th>Grupo</th>
                        </tr>
                    </thead>
                    <tbody id="leadsTableBody">
                    </tbody>
                </table>
            </div>
        </div>

        <script>
            // Configuração global
            const ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
            let charts = {};

            // Login
            document.getElementById('loginForm').addEventListener('submit', async function (e) {
                e.preventDefault();

                const username = document.getElementById('username').value;
                const password = document.getElementById('password').value;
                const loginBtn = document.getElementById('loginBtn');
                const errorMessage = document.getElementById('errorMessage');

                loginBtn.disabled = true;
                loginBtn.textContent = 'Entrando...';
                errorMessage.classList.remove('show');

                try {
                    const response = await fetch(ajaxurl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            action: 'hapvida_reports_login',
                            username: username,
                            password: password,
                            nonce: '<?php echo wp_create_nonce('hapvida_reports_login'); ?>'
                        })
                    });

                    const data = await response.json();

                    if (data.success) {
                        document.getElementById('loginContainer').style.display = 'none';
                        document.getElementById('dashboardContainer').classList.add('active');
                        initializeDashboard();
                    } else {
                        errorMessage.textContent = data.data.message || 'Erro ao fazer login';
                        errorMessage.classList.add('show');
                    }
                } catch (error) {
                    errorMessage.textContent = 'Erro de conexão. Tente novamente.';
                    errorMessage.classList.add('show');
                } finally {
                    loginBtn.disabled = false;
                    loginBtn.textContent = 'Entrar';
                }
            });

            // Logout
            document.getElementById('logoutBtn').addEventListener('click', async function () {
                try {
                    await fetch(ajaxurl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            action: 'hapvida_reports_logout'
                        })
                    });

                    document.getElementById('dashboardContainer').classList.remove('active');
                    document.getElementById('loginContainer').style.display = 'block';
                    document.getElementById('loginForm').reset();
                } catch (error) {
                    console.error('Erro ao fazer logout:', error);
                }
            });

            // Inicializa dashboard
            function initializeDashboard() {
                // Define datas padrão (últimos 30 dias)
                const today = new Date();
                const thirtyDaysAgo = new Date(today);
                thirtyDaysAgo.setDate(today.getDate() - 30);

                document.getElementById('startDate').valueAsDate = thirtyDaysAgo;
                document.getElementById('endDate').valueAsDate = today;

                // Carrega lista de vendedores
                loadVendorsList();

                // Carrega dados
                loadDashboardData();

                // Event listeners para filtros automáticos
                document.getElementById('startDate').addEventListener('change', loadDashboardData);
                document.getElementById('endDate').addEventListener('change', loadDashboardData);
                document.getElementById('vendorFilter').addEventListener('change', loadDashboardData);
                document.getElementById('filterBtn').addEventListener('click', loadDashboardData);

                // Quick filters
                document.querySelectorAll('.quick-filter-btn').forEach(btn => {
                    btn.addEventListener('click', function () {
                        document.querySelectorAll('.quick-filter-btn').forEach(b => b.classList.remove('active'));
                        this.classList.add('active');

                        const period = this.dataset.period;
                        const today = new Date();
                        let startDate = new Date(today);

                        if (period === 'this-month') {
                            startDate = new Date(today.getFullYear(), today.getMonth(), 1);
                        } else {
                            startDate.setDate(today.getDate() - parseInt(period));
                        }

                        document.getElementById('startDate').valueAsDate = startDate;
                        document.getElementById('endDate').valueAsDate = today;

                        loadDashboardData();
                    });
                });

                // Export CSV
                document.getElementById('exportBtn').addEventListener('click', exportToCSV);

                // Auto-refresh a cada 30 segundos
                setInterval(function () {
                    console.log('Auto-refresh: Recarregando dados do dashboard...');
                    loadDashboardData();
                }, 30000); // 30 segundos
            }

            // Carrega dados do dashboard
            // Carrega lista de vendedores
            async function loadVendorsList() {
                const formData = new URLSearchParams({
                    action: 'hapvida_get_vendors_list'
                });

                try {
                    const response = await fetch(ajaxurl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: formData
                    });
                    const data = await response.json();

                    if (data.success) {
                        const select = document.getElementById('vendorFilter');
                        select.innerHTML = '<option value="">Todos os vendedores</option>';

                        data.data.forEach(vendor => {
                            const option = document.createElement('option');
                            option.value = vendor.nome;
                            option.textContent = vendor.nome;
                            select.appendChild(option);
                        });
                    }
                } catch (error) {
                    console.error('Erro ao carregar vendedores:', error);
                }
            }

            async function loadDashboardData() {
                const startDate = document.getElementById('startDate').value;
                const endDate = document.getElementById('endDate').value;
                const vendorFilter = document.getElementById('vendorFilter').value;

                console.log('Carregando dados do dashboard...', { startDate, endDate, vendorFilter });

                const formData = new URLSearchParams({
                    action: 'hapvida_get_dashboard_stats',
                    start_date: startDate,
                    end_date: endDate,
                    vendor_filter: vendorFilter
                });

                try {
                    // Carrega estatísticas gerais
                    const statsResponse = await fetch(ajaxurl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: formData
                    });
                    const statsData = await statsResponse.json();

                    console.log('Resposta do servidor:', statsData);

                    if (statsData.success) {
                        updateStats(statsData.data);
                    } else {
                        console.error('Erro ao carregar stats:', statsData);
                    }

                    // Carrega outros dados em paralelo
                    Promise.all([
                        loadTimeline(startDate, endDate, vendorFilter),
                        loadCitiesData(startDate, endDate, vendorFilter),
                        loadVendorsData(startDate, endDate, vendorFilter),
                        loadPlanData(startDate, endDate, vendorFilter),
                        loadRecentLeads(startDate, endDate, vendorFilter)
                    ]);

                } catch (error) {
                    console.error('Erro ao carregar dados:', error);
                    handleAuthError(error);
                }
            }

            // Atualiza cards de estatísticas
            function updateStats(data) {
                console.log('Atualizando stats com dados:', data);

                // Total de Leads
                document.getElementById('totalLeads').textContent = data.total_leads.toLocaleString('pt-BR');

                // Valor Total (leads * R$ 11)
                const totalValue = data.total_leads * 12;
                document.getElementById('totalValue').textContent = 'R$ ' + totalValue.toLocaleString('pt-BR', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });

                // Média por Dia
                document.getElementById('avgPerDay').textContent = data.avg_leads_per_day.toLocaleString('pt-BR', {
                    minimumFractionDigits: 1,
                    maximumFractionDigits: 1
                });

                // Maior Pico
                document.getElementById('peakDay').textContent = data.peak_day_count.toLocaleString('pt-BR');

                // Label do pico (mostra a data)
                const peakLabel = document.getElementById('peakDayLabel');
                if (data.peak_day_date && data.peak_day_count > 0) {
                    const peakDate = new Date(data.peak_day_date);
                    const formattedDate = peakDate.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' });
                    peakLabel.textContent = 'Em ' + formattedDate;
                } else {
                    peakLabel.textContent = 'Nenhum lead no período';
                }

                console.log('Stats atualizados - Total:', data.total_leads, 'Média:', data.avg_leads_per_day, 'Pico:', data.peak_day_count);
            }

            // Carrega timeline
            async function loadTimeline(startDate, endDate, vendorFilter) {
                const response = await fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'hapvida_get_leads_timeline',
                        start_date: startDate,
                        end_date: endDate,
                        vendor_filter: vendorFilter
                    })
                });

                const data = await response.json();

                if (data.success) {
                    createTimelineChart(data.data);
                }
            }

            // Carrega dados de cidades
            async function loadCitiesData(startDate, endDate, vendorFilter) {
                const response = await fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'hapvida_get_leads_by_city',
                        start_date: startDate,
                        end_date: endDate,
                        vendor_filter: vendorFilter
                    })
                });

                const data = await response.json();

                if (data.success) {
                    createCitiesChart(data.data);
                }
            }

            // Carrega dados de vendedores
            async function loadVendorsData(startDate, endDate, vendorFilter) {
                const response = await fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'hapvida_get_leads_by_vendor',
                        start_date: startDate,
                        end_date: endDate,
                        vendor_filter: vendorFilter
                    })
                });

                const data = await response.json();

                if (data.success) {
                    createVendorsChart(data.data);
                }
            }

            // Carrega dados de planos
            async function loadPlanData(startDate, endDate, vendorFilter) {
                const response = await fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'hapvida_get_leads_by_plan',
                        start_date: startDate,
                        end_date: endDate,
                        vendor_filter: vendorFilter
                    })
                });

                const data = await response.json();

                if (data.success) {
                    createPlanChart(data.data);
                }
            }

            // Carrega leads recentes
            async function loadRecentLeads(startDate, endDate, vendorFilter) {
                const response = await fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'hapvida_get_recent_leads_report',
                        start_date: startDate,
                        end_date: endDate,
                        vendor_filter: vendorFilter,
                        limit: 50
                    })
                });

                const data = await response.json();

                if (data.success) {
                    renderLeadsTable(data.data);
                }
            }

            // Cria gráfico de timeline
            function createTimelineChart(timelineData) {
                const ctx = document.getElementById('timelineChart');

                if (charts.timeline) {
                    charts.timeline.destroy();
                }

                const labels = Object.keys(timelineData);
                const values = Object.values(timelineData);

                charts.timeline = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: labels.map(date => {
                            const d = new Date(date);
                            return d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
                        }),
                        datasets: [{
                            label: 'Leads por Dia',
                            data: values,
                            borderColor: '#667eea',
                            backgroundColor: 'rgba(102, 126, 234, 0.1)',
                            tension: 0.4,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });
            }

            // Cria gráfico de cidades
            function createCitiesChart(citiesData) {
                const ctx = document.getElementById('citiesChart');

                if (charts.cities) {
                    charts.cities.destroy();
                }

                // Pega top 10 cidades
                const entries = Object.entries(citiesData).slice(0, 10);
                const labels = entries.map(e => e[0]);
                const values = entries.map(e => e[1]);

                // Calcula total para percentuais
                const total = values.reduce((sum, val) => sum + val, 0);

                // Cria labels com percentuais
                const labelsWithPercent = entries.map(e => {
                    const percentage = total > 0 ? ((e[1] / total) * 100).toFixed(1) : 0;
                    return `${e[0]} (${percentage}%)`;
                });

                charts.cities = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: labelsWithPercent,
                        datasets: [{
                            label: 'Leads por Cidade',
                            data: values,
                            backgroundColor: '#667eea'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function (context) {
                                        const value = context.parsed.y;
                                        const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                        return `${value} leads (${percentage}%)`;
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });
            }

            // Cria gráfico de planos
            function createPlanChart(planData) {
                const ctx = document.getElementById('planChart');

                if (charts.plan) {
                    charts.plan.destroy();
                }

                const labels = Object.keys(planData);
                const values = Object.values(planData);

                charts.plan = new Chart(ctx, {
                    type: 'pie',
                    data: {
                        labels: labels,
                        datasets: [{
                            data: values,
                            backgroundColor: [
                                '#667eea',
                                '#764ba2',
                                '#f093fb'
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }
                });
            }

            // Cria gráfico de vendedores
            function createVendorsChart(vendorsData) {
                const ctx = document.getElementById('vendorsChart');

                if (charts.vendors) {
                    charts.vendors.destroy();
                }

                const entries = Object.values(vendorsData).slice(0, 10);
                const labels = entries.map(v => v.nome + ' (' + v.grupo.toUpperCase() + ')');
                const totalData = entries.map(v => v.total);

                charts.vendors = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [
                            {
                                label: 'Total de Leads',
                                data: totalData,
                                backgroundColor: '#667eea'
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    precision: 0
                                }
                            }
                        }
                    }
                });
            }

            // Renderiza tabela de leads
            function renderLeadsTable(leads) {
                const tbody = document.getElementById('leadsTableBody');
                tbody.innerHTML = '';

                leads.forEach(lead => {
                    const row = document.createElement('tr');

                    const date = new Date(lead.criado_em);
                    const formattedDate = date.toLocaleDateString('pt-BR') + ' ' + date.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });

                    const grupo = lead.grupo ? lead.grupo.toUpperCase() : 'N/A';
                    const tipoPlano = lead.qtd_pessoas && parseInt(lead.qtd_pessoas) > 1 ? 'Empresarial' : 'Individual';

                    row.innerHTML = `
                    <td>${formattedDate}</td>
                    <td>${lead.nome}</td>
                    <td>${lead.telefone}</td>
                    <td>${lead.cidade || 'N/A'}</td>
                    <td>${lead.plano || 'N/A'}</td>
                    <td>${lead.qtd_pessoas || '1'} (${tipoPlano})</td>
                    <td>${lead.vendedor}</td>
                    <td><span style="background: ${grupo === 'DRV' ? '#e3f2fd' : '#fff3e0'}; padding: 3px 8px; border-radius: 3px; font-weight: 600; font-size: 11px;">${grupo}</span></td>
                `;

                    tbody.appendChild(row);
                });

                document.getElementById('tableLoading').style.display = 'none';
                document.getElementById('leadsTable').style.display = 'table';
            }

            // Exporta para CSV
            async function exportToCSV() {
                const startDate = document.getElementById('startDate').value;
                const endDate = document.getElementById('endDate').value;
                const vendorFilter = document.getElementById('vendorFilter').value;

                try {
                    const response = await fetch(ajaxurl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'hapvida_export_leads_csv',
                            start_date: startDate,
                            end_date: endDate,
                            vendor_filter: vendorFilter
                        })
                    });

                    const data = await response.json();

                    if (data.success) {
                        const csvContent = data.data.csv_data.map(row =>
                            row.map(cell => `"${cell}"`).join(',')
                        ).join('\n');

                        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                        const link = document.createElement('a');
                        const url = URL.createObjectURL(blob);

                        link.setAttribute('href', url);
                        link.setAttribute('download', `leads-hapvida-${startDate}-${endDate}.csv`);
                        link.style.visibility = 'hidden';

                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                    }
                } catch (error) {
                    console.error('Erro ao exportar CSV:', error);
                    alert('Erro ao exportar dados. Tente novamente.');
                }
            }


            // Tratamento de erro de autenticação
            function handleAuthError(error) {
                if (error.code === 'not_authenticated') {
                    document.getElementById('dashboardContainer').classList.remove('active');
                    document.getElementById('loginContainer').style.display = 'block';
                    document.getElementById('errorMessage').textContent = 'Sessão expirada. Faça login novamente.';
                    document.getElementById('errorMessage').classList.add('show');
                }
            }

            // Verifica se está autenticado ao carregar a página
            <?php if (isset($authenticated) && $authenticated): ?>
                console.log('Usuário já autenticado, inicializando dashboard...');
                // Inicializa dashboard automaticamente
                document.addEventListener('DOMContentLoaded', function () {
                    initializeDashboard();
                });
            <?php endif; ?>
        </script>

</div> <!-- .hapvida-reports-wrapper -->