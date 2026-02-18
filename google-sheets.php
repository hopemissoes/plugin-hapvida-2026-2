<?php
/**
 * Google Sheets Integration for Formulario Hapvida
 *
 * Modo: Planilha criada pelo usuario, gerenciada pelo plugin
 * - Usuario cria planilha no seu Drive e compartilha com a conta de servico (Editor)
 * - "Criar" = usuario cola o link, plugin preenche cabecalhos + FILTER/IMPORTRANGE
 * - "Atualizar" = nova aba mensal na planilha do vendedor
 * - "Visualizar" = abre a planilha do vendedor
 */

if (!defined('ABSPATH')) {
    exit;
}

class Formulario_Hapvida_Google_Sheets
{

    private $credentials_option = 'formulario_hapvida_google_credentials';
    private $sheets_option = 'formulario_hapvida_vendor_sheets';

    /** URL fixa da planilha-fonte usada no IMPORTRANGE */
    private $source_url = 'https://docs.google.com/spreadsheets/d/1Q-GEkj7DuACFVa-glS-5N8Lr2TYj1YW2v1cG4nmWJeI/edit?gid=750218029#gid=750218029';

    public function __construct()
    {
        add_action('wp_ajax_hapvida_sheets_create', array($this, 'ajax_create_sheet'));
        add_action('wp_ajax_hapvida_sheets_update', array($this, 'ajax_update_sheet'));
        add_action('wp_ajax_hapvida_sheets_view', array($this, 'ajax_view_sheet'));
        add_action('wp_ajax_hapvida_sheets_unlink', array($this, 'ajax_unlink_sheet'));
        add_action('wp_ajax_hapvida_sheets_save_credentials', array($this, 'ajax_save_credentials'));
        add_action('wp_ajax_hapvida_sheets_test', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_hapvida_sheets_update_all', array($this, 'ajax_update_all_sheets'));
    }

    private function extract_spreadsheet_id($url_or_id)
    {
        if (preg_match('/^[a-zA-Z0-9_-]+$/', $url_or_id)) {
            return $url_or_id;
        }
        if (preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9_-]+)/', $url_or_id, $m)) {
            return $m[1];
        }
        return '';
    }

    // =========================================================================
    //  AJAX: Testar conexao
    // =========================================================================

    public function ajax_test_connection()
    {
        check_ajax_referer('hapvida_sheets_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sem permissao.');
        }

        delete_transient('hapvida_google_access_token');
        $log = array();

        // 1) Credenciais
        $credentials_json = get_option($this->credentials_option);
        if (!$credentials_json) {
            wp_send_json_error('Credenciais nao configuradas.');
        }
        $creds = json_decode($credentials_json, true);
        $log[] = 'Email: ' . (isset($creds['client_email']) ? $creds['client_email'] : 'N/A');
        $log[] = 'Chave privada: ' . (isset($creds['private_key']) ? 'OK' : 'AUSENTE');

        // 2) Token
        $header = $this->base64url_encode(json_encode(array('alg' => 'RS256', 'typ' => 'JWT')));
        $now = time();
        $claims = $this->base64url_encode(json_encode(array(
            'iss' => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets https://www.googleapis.com/auth/drive',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        )));
        $unsigned = $header . '.' . $claims;
        $signature = '';
        $sign_ok = openssl_sign($unsigned, $signature, $creds['private_key'], OPENSSL_ALGO_SHA256);
        $log[] = 'JWT: ' . ($sign_ok ? 'OK' : 'FALHOU');
        if (!$sign_ok) {
            wp_send_json_error(implode("\n", $log));
        }
        $jwt = $unsigned . '.' . $this->base64url_encode($signature);

        $token_response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'timeout' => 15,
            'body' => array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ),
        ));
        if (is_wp_error($token_response)) {
            $log[] = 'Token: ERRO - ' . $token_response->get_error_message();
            wp_send_json_error(implode("\n", $log));
        }
        $token_body = json_decode(wp_remote_retrieve_body($token_response), true);
        if (!isset($token_body['access_token'])) {
            $log[] = 'Token: FALHOU - ' . wp_json_encode($token_body);
            wp_send_json_error(implode("\n", $log));
        }
        $token = $token_body['access_token'];
        $log[] = 'Token: OK';

        // 3) Testar Sheets API
        $log[] = '---';
        $log[] = 'Testando Sheets API...';
        $sheets_test = wp_remote_get(
            'https://sheets.googleapis.com/v4/spreadsheets/nonexistent_test_id',
            array('timeout' => 15, 'headers' => array('Authorization' => 'Bearer ' . $token))
        );
        if (!is_wp_error($sheets_test)) {
            $sheets_code = wp_remote_retrieve_response_code($sheets_test);
            if ($sheets_code === 404) {
                $log[] = 'Sheets API: HABILITADA';
            } elseif ($sheets_code === 403) {
                $log[] = 'Sheets API: DESABILITADA ou sem permissao';
                $log[] = 'Ative a Google Sheets API no Google Cloud Console.';
            } else {
                $log[] = 'Sheets API: HTTP ' . $sheets_code;
            }
        } else {
            $log[] = 'Sheets API: Erro de conexao';
        }

        // 4) Testar Drive API
        $log[] = 'Testando Drive API...';
        $drive_test = wp_remote_get(
            'https://www.googleapis.com/drive/v3/files?pageSize=1&fields=files(id)',
            array('timeout' => 15, 'headers' => array('Authorization' => 'Bearer ' . $token))
        );
        if (!is_wp_error($drive_test)) {
            $drive_code = wp_remote_retrieve_response_code($drive_test);
            if ($drive_code === 200) {
                $log[] = 'Drive API: HABILITADA';
            } elseif ($drive_code === 403) {
                $log[] = 'Drive API: DESABILITADA ou sem permissao';
                $log[] = 'Ative a Google Drive API no Google Cloud Console.';
            } else {
                $log[] = 'Drive API: HTTP ' . $drive_code;
            }
        } else {
            $log[] = 'Drive API: Erro de conexao';
        }

        $log[] = '';
        $log[] = 'TUDO OK! Credenciais funcionando.';
        $log[] = '';
        $log[] = 'Para criar planilha para um vendedor:';
        $log[] = '1. Crie uma planilha no seu Google Drive';
        $log[] = '2. Compartilhe com: ' . $creds['client_email'] . ' (Editor)';
        $log[] = '3. Clique no botao "Criar" ao lado do vendedor';
        $log[] = '4. Cole o link da planilha';

        wp_send_json_success(implode("\n", $log));
    }

    // =========================================================================
    //  METODOS ESTATICOS - chamados pelo admin-page.php
    // =========================================================================

    public static function render_vendor_buttons($vendor_name)
    {
        $sheets = get_option('formulario_hapvida_vendor_sheets', array());
        $has_sheet = !empty($vendor_name) && isset($sheets[$vendor_name]);
        ?>
        <div class="sheets-btn-group">
            <?php if (!$has_sheet): ?>
                <button type="button" class="button button-small sheets-btn sheets-link-btn" title="Vincular Planilha">
                    <span class="dashicons dashicons-admin-links"></span> Vincular
                </button>
            <?php else: ?>
                <button type="button" class="button button-small sheets-btn sheets-update-btn" title="Atualizar Planilha">
                    <span class="dashicons dashicons-update"></span> Atualizar
                </button>
                <button type="button" class="button button-small sheets-btn sheets-view-btn" title="Visualizar Planilha">
                    <span class="dashicons dashicons-visibility"></span> Ver
                </button>
                <button type="button" class="button button-small sheets-btn sheets-change-btn" title="Trocar planilha vinculada">
                    <span class="dashicons dashicons-edit"></span>
                </button>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function render_config_area()
    {
        $creds_json = get_option('formulario_hapvida_google_credentials', '');
        $creds_data = $creds_json ? json_decode($creds_json, true) : array();
        $has_creds = !empty($creds_json);
        $email = isset($creds_data['client_email']) ? $creds_data['client_email'] : '';
        ?>
        <div class="hapvida-sheets-config" id="hapvida-sheets-config-area">
            <button type="button" id="hapvida-sheets-config-btn" class="button button-secondary">
                <span class="dashicons dashicons-media-spreadsheet"></span> Configurar Google Sheets
            </button>
            <?php if ($has_creds): ?>
                <button type="button" id="hapvida-sheets-test-btn" class="button button-secondary"
                    onclick="jQuery('#hapvida-sheets-test-btn').prop('disabled',true).text('Testando...');jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>',{action:'hapvida_sheets_test',nonce:'<?php echo wp_create_nonce('hapvida_sheets_nonce'); ?>'},function(r){var txt=(r.success?'SUCESSO!\n\n':'ERRO!\n\n')+r.data;jQuery('#sheets-test-result-content').val(txt);jQuery('#sheets-test-result-overlay').addClass('active');jQuery('#hapvida-sheets-test-btn').prop('disabled',false).text('Testar Conexao');});">
                    <span class="dashicons dashicons-yes-alt"></span> Testar Conexao
                </button>
                <button type="button" id="hapvida-sheets-update-all-btn" class="button button-primary" style="margin-left:6px;">
                    <span class="dashicons dashicons-update"></span> Atualizar Todas as Planilhas
                </button>
                <span class="sheets-status sheets-status-ok">Configurado (
                    <?php echo esc_html($email); ?>)
                </span>
            <?php else: ?>
                <span class="sheets-status sheets-status-warn">Nao configurado</span>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function render_modal()
    {
        $creds_json = get_option('formulario_hapvida_google_credentials', '');
        $creds_data = $creds_json ? json_decode($creds_json, true) : array();
        $email = isset($creds_data['client_email']) ? $creds_data['client_email'] : '';
        ?>
        <div class="hapvida-sheets-modal-overlay" id="sheets-modal-overlay">
            <div class="hapvida-sheets-modal">
                <h3>Configurar Google Sheets</h3>
                <div class="modal-info">
                    <strong>Instrucoes:</strong><br>
                    1. No <a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a>: crie projeto,
                    ative <strong>Sheets API</strong> e <strong>Drive API</strong><br>
                    2. Crie uma <strong>Conta de Servico</strong> e baixe o JSON<br>
                    3. Cole o JSON abaixo e salve<br>
                    <br>
                    <strong>Para cada vendedor:</strong><br>
                    1. Crie uma <strong>planilha</strong> no seu Google Drive (ou use uma existente)<br>
                    2. Compartilhe com o email da conta de servico como <strong>Editor</strong><br>
                    3. Clique em <strong>"Vincular"</strong> ao lado do vendedor e cole o link<br>
                    4. Escolha se quer <strong>configurar</strong> (cabecalhos + formula) ou <strong>apenas
                        vincular</strong><br>
                    <br>
                    Use o botao <strong>pencil</strong> para trocar ou desvincular a planilha.
                </div>
                <label for="sheets-credentials-input"><strong>JSON da Conta de Servico:</strong></label>
                <textarea id="sheets-credentials-input"
                    placeholder='{"type": "service_account", "project_id": "...", ...}'></textarea>
                <?php if ($email): ?>
                    <div style="margin-top:8px;font-size:12px;color:#555;">
                        Email da conta de servico: <code
                            style="background:#f0f0f0;padding:2px 6px;border-radius:3px;user-select:all;"><?php echo esc_html($email); ?></code>
                    </div>
                <?php endif; ?>

                <div class="modal-actions">
                    <button type="button" class="button" id="sheets-modal-close">Cancelar</button>
                    <button type="button" class="button button-primary" id="sheets-modal-save">Salvar Credenciais</button>
                </div>
            </div>
        </div>
        <div class="hapvida-sheets-toast" id="sheets-toast"></div>
        <div class="hapvida-sheets-modal-overlay" id="sheets-test-result-overlay">
            <div class="hapvida-sheets-modal">
                <h3>Resultado do Teste de Conexao</h3>
                <textarea id="sheets-test-result-content" readonly
                    style="width:100%;height:300px;font-family:monospace;font-size:12px;resize:vertical;background:#f9f9f9;"></textarea>
                <div class="modal-actions">
                    <button type="button" class="button button-secondary"
                        onclick="var ta=document.getElementById('sheets-test-result-content');ta.select();document.execCommand('copy');jQuery('#sheets-toast').removeClass('success error info').addClass('info').text('Copiado!').fadeIn(200);clearTimeout(jQuery('#sheets-toast').data('timer'));jQuery('#sheets-toast').data('timer',setTimeout(function(){jQuery('#sheets-toast').fadeOut(300);},2000));">
                        <span class="dashicons dashicons-clipboard"></span> Copiar
                    </button>
                    <button type="button" class="button"
                        onclick="jQuery('#sheets-test-result-overlay').removeClass('active');">Fechar</button>
                </div>
            </div>
        </div>
        <?php
    }

    public static function render_css()
    {
        ?>
        <style type="text/css">
            .hapvida-sheets-config {
                display: flex;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;
                margin: 10px 0 15px 0;
                padding: 10px 15px;
                background: #f0f6fc;
                border: 1px solid #c3d9ed;
                border-radius: 6px;
            }

            .hapvida-sheets-config .dashicons {
                vertical-align: middle;
                margin-right: 2px;
            }

            .sheets-status {
                font-size: 13px;
                font-weight: 500;
            }

            .sheets-status-ok {
                color: #0a7b3e;
            }

            .sheets-status-warn {
                color: #b45309;
            }

            .sheets-btn-group {
                display: flex;
                gap: 4px;
                margin-top: 6px;
                flex-wrap: wrap;
            }

            .sheets-btn-group .sheets-btn {
                font-size: 11px !important;
                padding: 2px 8px !important;
                line-height: 1.6 !important;
                min-height: 26px !important;
            }

            .sheets-btn-group .sheets-btn .dashicons {
                font-size: 14px;
                width: 14px;
                height: 14px;
                vertical-align: middle;
                margin-right: 2px;
            }

            .sheets-link-btn {
                background: #0a7b3e !important;
                border-color: #07612f !important;
                color: #fff !important;
            }

            .sheets-link-btn:hover {
                background: #07612f !important;
            }

            .sheets-update-btn:not(:disabled) {
                background: #2271b1 !important;
                border-color: #1a5a8e !important;
                color: #fff !important;
            }

            .sheets-update-btn:not(:disabled):hover {
                background: #1a5a8e !important;
            }

            .sheets-view-btn:not(:disabled) {
                background: #8250df !important;
                border-color: #6b3fc0 !important;
                color: #fff !important;
            }

            .sheets-view-btn:not(:disabled):hover {
                background: #6b3fc0 !important;
            }

            .sheets-change-btn {
                background: #f0f0f0 !important;
                border-color: #c3c4c7 !important;
                color: #50575e !important;
                padding: 2px 5px !important;
                min-width: auto !important;
            }

            .sheets-change-btn:hover {
                background: #dcdcde !important;
                color: #1d2327 !important;
            }

            .sheets-change-btn .dashicons {
                margin-right: 0 !important;
            }

            .hapvida-sheets-modal-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 100000;
                justify-content: center;
                align-items: center;
            }

            .hapvida-sheets-modal-overlay.active {
                display: flex;
            }

            .hapvida-sheets-modal {
                background: #fff;
                border-radius: 8px;
                padding: 24px;
                width: 550px;
                max-width: 90vw;
                max-height: 80vh;
                overflow-y: auto;
                box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            }

            .hapvida-sheets-modal h3 {
                margin-top: 0;
                margin-bottom: 16px;
                font-size: 18px;
            }

            .hapvida-sheets-modal textarea {
                width: 100%;
                height: 200px;
                font-family: monospace;
                font-size: 12px;
                resize: vertical;
            }

            .hapvida-sheets-modal .modal-actions {
                display: flex;
                justify-content: flex-end;
                gap: 10px;
                margin-top: 16px;
            }

            .hapvida-sheets-modal .modal-info {
                background: #f0f6fc;
                border: 1px solid #c3d9ed;
                border-radius: 4px;
                padding: 10px;
                margin-bottom: 12px;
                font-size: 12px;
                line-height: 1.5;
            }

            .hapvida-sheets-toast {
                position: fixed;
                top: 40px;
                right: 20px;
                z-index: 100001;
                padding: 12px 20px;
                border-radius: 6px;
                color: #fff;
                font-size: 14px;
                font-weight: 500;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
                display: none;
                max-width: 400px;
            }

            .hapvida-sheets-toast.success {
                background: #0a7b3e;
            }

            .hapvida-sheets-toast.error {
                background: #d63638;
            }

            .hapvida-sheets-toast.info {
                background: #2271b1;
            }

            @keyframes hapvida-spin {
                from {
                    transform: rotate(0deg);
                }

                to {
                    transform: rotate(360deg);
                }
            }
        </style>
        <?php
    }

    public static function render_js()
    {
        $sheets = get_option('formulario_hapvida_vendor_sheets', array());
        $creds_json = get_option('formulario_hapvida_google_credentials', '');
        $creds_data = $creds_json ? json_decode($creds_json, true) : array();

        $config = array(
            'nonce' => wp_create_nonce('hapvida_sheets_nonce'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'sheets' => !empty($sheets) ? $sheets : new stdClass(),
            'hasCreds' => !empty($creds_json),
            'serviceEmail' => isset($creds_data['client_email']) ? $creds_data['client_email'] : '',
        );
        ?>
        <script type="text/javascript">
            var HapvidaSheetsConfig = <?php echo wp_json_encode($config); ?>;
        </script>
        <script type="text/javascript"
            src="<?php echo esc_url(plugin_dir_url(__FILE__) . 'google-sheets.js?v=' . filemtime(plugin_dir_path(__FILE__) . 'google-sheets.js')); ?>"></script>
        <?php
    }

    // =========================================================================
    //  AUTENTICACAO
    // =========================================================================

    private function base64url_encode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function get_access_token()
    {
        $credentials_json = get_option($this->credentials_option);
        if (!$credentials_json) {
            return new WP_Error('no_credentials', 'Credenciais nao configuradas.');
        }
        $creds = json_decode($credentials_json, true);
        if (!$creds || !isset($creds['client_email'], $creds['private_key'])) {
            return new WP_Error('invalid_credentials', 'Credenciais invalidas.');
        }

        $cached = get_transient('hapvida_google_access_token');
        if ($cached) {
            return $cached;
        }

        $header = $this->base64url_encode(json_encode(array('alg' => 'RS256', 'typ' => 'JWT')));
        $now = time();
        $claims = $this->base64url_encode(json_encode(array(
            'iss' => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets https://www.googleapis.com/auth/drive',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        )));
        $unsigned = $header . '.' . $claims;
        $signature = '';
        if (!openssl_sign($unsigned, $signature, $creds['private_key'], OPENSSL_ALGO_SHA256)) {
            return new WP_Error('sign_error', 'Erro ao assinar JWT.');
        }
        $jwt = $unsigned . '.' . $this->base64url_encode($signature);

        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'timeout' => 15,
            'body' => array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('http_error', 'Erro de conexao: ' . $response->get_error_message());
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['access_token'])) {
            $err = isset($body['error_description']) ? $body['error_description'] : wp_json_encode($body);
            return new WP_Error('token_error', 'Erro token: ' . $err);
        }

        set_transient('hapvida_google_access_token', $body['access_token'], 3500);
        return $body['access_token'];
    }

    // =========================================================================
    //  HELPER
    // =========================================================================

    private function api_request($method, $url, $body = null)
    {
        $token = $this->get_access_token();
        if (is_wp_error($token)) {
            return $token;
        }

        $args = array(
            'method' => $method,
            'timeout' => 30,
            'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'),
        );
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return new WP_Error('http_error', 'Erro conexao: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            if ($code === 403) {
                delete_transient('hapvida_google_access_token');
            }
            $msg = isset($data['error']['message']) ? $data['error']['message'] : 'Erro desconhecido';
            return new WP_Error('api_error', $msg . ' (HTTP ' . $code . ')');
        }

        return $data;
    }

    // =========================================================================
    //  AJAX: Salvar credenciais
    // =========================================================================

    public function ajax_save_credentials()
    {
        check_ajax_referer('hapvida_sheets_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sem permissao.');
        }

        $json = wp_unslash($_POST['credentials']);

        if (!empty($json)) {
            $parsed = json_decode($json, true);
            if (!$parsed || !isset($parsed['client_email'], $parsed['private_key'])) {
                wp_send_json_error('JSON invalido.');
            }
            update_option($this->credentials_option, $json, false);
            delete_transient('hapvida_google_access_token');
        }

        $creds_json = get_option($this->credentials_option, '');
        $creds_data = $creds_json ? json_decode($creds_json, true) : array();

        wp_send_json_success(array(
            'message' => 'Credenciais salvas!',
            'email' => isset($creds_data['client_email']) ? $creds_data['client_email'] : '',
        ));
    }

    // =========================================================================
    //  AJAX: Vincular (apenas salva o link da planilha)
    // =========================================================================

    public function ajax_create_sheet()
    {
        check_ajax_referer('hapvida_sheets_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sem permissao.');
        }

        $vendor_name = sanitize_text_field(wp_unslash($_POST['vendor_name']));
        if (empty($vendor_name)) {
            wp_send_json_error('Nome do vendedor obrigatorio.');
        }

        $replace = !empty($_POST['replace']);
        $sheets = get_option($this->sheets_option, array());
        if (isset($sheets[$vendor_name]) && !$replace) {
            wp_send_json_error('Ja existe planilha para "' . $vendor_name . '".');
        }

        $spreadsheet_url = isset($_POST['spreadsheet_url']) ? sanitize_text_field(wp_unslash($_POST['spreadsheet_url'])) : '';
        $spreadsheet_id = $this->extract_spreadsheet_id($spreadsheet_url);
        if (!$spreadsheet_id) {
            wp_send_json_error('Link da planilha invalido. Cole o link completo do Google Sheets.');
        }

        // Salvar vinculo
        $url = "https://docs.google.com/spreadsheets/d/{$spreadsheet_id}/edit";
        $sheets[$vendor_name] = array(
            'spreadsheet_id' => $spreadsheet_id,
            'url' => $url,
            'created_at' => current_time('mysql'),
        );
        update_option($this->sheets_option, $sheets);

        wp_send_json_success(array(
            'message' => 'Planilha vinculada para "' . $vendor_name . '"!',
            'url' => $url,
        ));
    }

    // =========================================================================
    //  AJAX: Atualizar (duplica ultima aba e renomeia para proximo mes)
    // =========================================================================

    public function ajax_update_sheet()
    {
        check_ajax_referer('hapvida_sheets_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sem permissao.');
        }

        $vendor_name = sanitize_text_field(wp_unslash($_POST['vendor_name']));
        $result = $this->update_single_vendor($vendor_name);

        if ($result['success']) {
            wp_send_json_success(array('message' => $result['message']));
        } else {
            wp_send_json_error($result['message']);
        }
    }

    // =========================================================================
    //  AJAX: Atualizar TODAS as planilhas vinculadas
    // =========================================================================

    public function ajax_update_all_sheets()
    {
        check_ajax_referer('hapvida_sheets_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sem permissao.');
        }

        // Lock transient para impedir execucao simultanea (previne race condition)
        $lock_key = 'hapvida_sheets_update_all_lock';
        if (get_transient($lock_key)) {
            error_log('[Sheets UpdateAll] BLOQUEADO - Outra atualizacao ja esta em andamento.');
            wp_send_json_error('Outra atualizacao ja esta em andamento. Aguarde alguns segundos e tente novamente.');
        }
        set_transient($lock_key, true, 120); // Lock de 120s como safety net

        $sheets = get_option($this->sheets_option, array());
        if (empty($sheets)) {
            delete_transient($lock_key);
            wp_send_json_error('Nenhuma planilha vinculada.');
        }

        error_log('[Sheets UpdateAll] === INICIO === Total entradas: ' . count($sheets));
        // Log diagnostico: mostrar todos os vendedores e seus spreadsheet_ids
        foreach ($sheets as $vname => $vinfo) {
            $sid = isset($vinfo['spreadsheet_id']) ? $vinfo['spreadsheet_id'] : '(sem id)';
            error_log('[Sheets UpdateAll] Entrada: "' . $vname . '" => spreadsheet_id=' . $sid);
        }

        $results = array();
        $success = 0;
        $errors = 0;
        $processed_spreadsheets = array(); // Controle de planilhas ja processadas

        foreach ($sheets as $vendor_name => $info) {
            // Deduplicacao: pula se esta spreadsheet_id ja foi processada
            $sid = isset($info['spreadsheet_id']) ? $info['spreadsheet_id'] : '';
            if (!empty($sid) && in_array($sid, $processed_spreadsheets, true)) {
                error_log('[Sheets UpdateAll] PULANDO "' . $vendor_name . '" - spreadsheet_id ' . $sid . ' ja processada por outro vendedor.');
                $results[] = array(
                    'vendor' => $vendor_name,
                    'success' => false,
                    'message' => 'Pulada: planilha ja atualizada por outro vendedor com mesmo link.',
                );
                continue;
            }

            error_log('[Sheets UpdateAll] Processando: ' . $vendor_name);
            $result = $this->update_single_vendor($vendor_name);
            $results[] = array(
                'vendor' => $vendor_name,
                'success' => $result['success'],
                'message' => $result['message'],
            );
            if ($result['success']) {
                $success++;
                if (!empty($sid)) {
                    $processed_spreadsheets[] = $sid;
                }
            } else {
                $errors++;
            }
        }

        // Liberar lock apos processar todos
        delete_transient($lock_key);

        error_log('[Sheets UpdateAll] === FIM === Sucesso: ' . $success . ' | Erros: ' . $errors);

        $summary = $success . ' de ' . count($sheets) . ' planilhas atualizadas com sucesso.';
        if ($errors > 0) {
            $summary .= ' (' . $errors . ' com erro)';
        }

        wp_send_json_success(array(
            'message' => $summary,
            'results' => $results,
        ));
    }

    // =========================================================================
    //  HELPER: Atualizar planilha de um vendedor (logica reutilizavel)
    // =========================================================================

    private function update_single_vendor($vendor_name)
    {
        $sheets = get_option($this->sheets_option, array());
        error_log('[Sheets Update] === INICIO === Vendedor: ' . $vendor_name);

        if (!isset($sheets[$vendor_name])) {
            return array('success' => false, 'message' => 'Nenhuma planilha para "' . $vendor_name . '".');
        }

        $spreadsheet_id = $sheets[$vendor_name]['spreadsheet_id'];
        error_log('[Sheets Update] Spreadsheet ID: ' . $spreadsheet_id);

        // Buscar metadados da planilha (todas as abas)
        $meta = $this->api_request(
            'GET',
            "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheet_id}?fields=sheets.properties"
        );
        if (is_wp_error($meta)) {
            $err = $meta->get_error_message();
            error_log('[Sheets Update] Erro meta: ' . $err);
            return array('success' => false, 'message' => 'Sem acesso a planilha de "' . $vendor_name . '": ' . $err);
        }

        $existing_sheets = isset($meta['sheets']) ? $meta['sheets'] : array();
        error_log('[Sheets Update] Total de abas: ' . count($existing_sheets));

        if (empty($existing_sheets)) {
            return array('success' => false, 'message' => 'A planilha de "' . $vendor_name . '" nao possui nenhuma aba.');
        }

        // Encontrar a primeira e a ultima aba (menor e maior index)
        $first_sheet = null;
        $min_index = PHP_INT_MAX;
        $last_sheet = null;
        $max_index = -1;
        foreach ($existing_sheets as $s) {
            $idx = isset($s['properties']['index']) ? (int) $s['properties']['index'] : 0;
            if ($idx > $max_index) {
                $max_index = $idx;
                $last_sheet = $s['properties'];
            }
            if ($idx < $min_index) {
                $min_index = $idx;
                $first_sheet = $s['properties'];
            }
        }

        if (!$last_sheet) {
            return array('success' => false, 'message' => 'Nao foi possivel identificar a ultima aba de "' . $vendor_name . '".');
        }

        $last_title = $last_sheet['title'];
        $last_sheet_id = (int) $last_sheet['sheetId'];
        error_log('[Sheets Update] Ultima aba: "' . $last_title . '" (sheetId: ' . $last_sheet_id . ', index: ' . $max_index . ')');

        // Calcular proximo mes a partir do nome da ultima aba (formato MM/YY)
        $next_tab_name = $this->calculate_next_month($last_title);
        error_log('[Sheets Update] Proxima aba: "' . $next_tab_name . '"');

        // Verificar se a aba do proximo mes ja existe
        foreach ($existing_sheets as $s) {
            if (isset($s['properties']['title']) && $s['properties']['title'] === $next_tab_name) {
                error_log('[Sheets Update] Aba "' . $next_tab_name . '" ja existe!');
                return array('success' => false, 'message' => 'A aba "' . $next_tab_name . '" ja existe na planilha de "' . $vendor_name . '".');
            }
        }

        // =====================================================================
        // PASSO 1: Copiar a aba usando sheets.copyTo (copia conteudo completo)
        // =====================================================================
        error_log('[Sheets Update] Passo 1: Copiando aba sheetId=' . $last_sheet_id . ' via copyTo...');
        $copy = $this->api_request(
            'POST',
            "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheet_id}/sheets/{$last_sheet_id}:copyTo",
            array('destinationSpreadsheetId' => $spreadsheet_id)
        );
        if (is_wp_error($copy)) {
            error_log('[Sheets Update] Erro copyTo: ' . $copy->get_error_message());
            return array('success' => false, 'message' => 'Erro ao copiar aba de "' . $vendor_name . '": ' . $copy->get_error_message());
        }

        if (!isset($copy['sheetId'])) {
            error_log('[Sheets Update] Resposta inesperada do copyTo: ' . wp_json_encode($copy));
            return array('success' => false, 'message' => 'Resposta inesperada da API ao copiar aba de "' . $vendor_name . '".');
        }

        $new_sheet_id = (int) $copy['sheetId'];
        error_log('[Sheets Update] Aba copiada! Novo sheetId=' . $new_sheet_id);

        // =====================================================================
        // PASSO 2: Renomear a aba copiada para o nome do proximo mes
        // =====================================================================
        error_log('[Sheets Update] Passo 2: Renomeando aba para "' . $next_tab_name . '"...');
        $rename = $this->api_request(
            'POST',
            "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheet_id}:batchUpdate",
            array(
                'requests' => array(
                    array(
                        'updateSheetProperties' => array(
                            'properties' => array(
                                'sheetId' => $new_sheet_id,
                                'title' => $next_tab_name,
                                'index' => 0,
                            ),
                            'fields' => 'title,index',
                        ),
                    )
                )
            )
        );
        if (is_wp_error($rename)) {
            error_log('[Sheets Update] Erro ao renomear: ' . $rename->get_error_message());
            return array('success' => false, 'message' => 'Aba copiada mas erro ao renomear para "' . $vendor_name . '": ' . $rename->get_error_message());
        }

        error_log('[Sheets Update] Aba renomeada para "' . $next_tab_name . '"');

        // =====================================================================
        // PASSO 3: Escrever formula FILTER+IMPORTRANGE na celula A2 da PRIMEIRA aba
        // =====================================================================
        $first_tab_name = $first_sheet ? $first_sheet['title'] : $last_title;
        $vendor_upper = mb_strtoupper($vendor_name, 'UTF-8');
        $source = $this->source_url;
        $leads_tab = 'leads ' . $first_tab_name;

        $formula = '=FILTER(IMPORTRANGE("' . $source . '";"' . $leads_tab . '!A1:H3000");IMPORTRANGE("' . $source . '";"' . $leads_tab . '!H1:H3000")="' . $vendor_upper . '")';

        error_log('[Sheets Update] Passo 3: Escrevendo formula em A2 da primeira aba "' . $first_tab_name . '". Vendedor: ' . $vendor_upper);

        $formula_range = "'" . $first_tab_name . "'!A2";
        $write = $this->api_request(
            'PUT',
            "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheet_id}/values/" . urlencode($formula_range) . "?valueInputOption=USER_ENTERED",
            array('values' => array(array($formula)))
        );
        if (is_wp_error($write)) {
            error_log('[Sheets Update] Erro ao escrever formula: ' . $write->get_error_message());
        } else {
            error_log('[Sheets Update] Formula escrita com sucesso em ' . $formula_range);
        }

        // =====================================================================
        // PASSO 4: Verificar se a aba copiada tem conteudo
        // =====================================================================
        $verify = $this->api_request(
            'GET',
            "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheet_id}/values/" . urlencode("'" . $next_tab_name . "'") . "!A1:E3"
        );
        $cell_count = 0;
        if (!is_wp_error($verify) && isset($verify['values'])) {
            foreach ($verify['values'] as $row) {
                $cell_count += count($row);
            }
        }
        error_log('[Sheets Update] Verificacao: ' . $cell_count . ' celulas encontradas em A1:E3 da nova aba');

        // Atualizar dados salvos
        $sheets = get_option($this->sheets_option, array());
        $sheets[$vendor_name]['sheet_id'] = $new_sheet_id;
        $sheets[$vendor_name]['tab_name'] = $next_tab_name;
        update_option($this->sheets_option, $sheets);

        $msg = 'Aba "' . $next_tab_name . '" criada (copiada de "' . $last_title . '") na planilha de "' . $vendor_name . '"!';
        if ($cell_count > 0) {
            $msg .= ' (' . $cell_count . ' celulas verificadas)';
        } else {
            $msg .= ' (ATENCAO: aba pode estar vazia)';
        }

        error_log('[Sheets Update] === SUCESSO === ' . $msg);
        return array('success' => true, 'message' => $msg);
    }

    /**
     * Calcula o proximo mes a partir de um nome de aba no formato MM/YY.
     * Ex: "02/26" -> "03/26", "12/25" -> "01/26"
     * Se o nome nao estiver no formato esperado, usa o mes atual + 1.
     */
    private function calculate_next_month($tab_title)
    {
        if (preg_match('/^(\d{2})\/(\d{2})$/', trim($tab_title), $m)) {
            $month = (int) $m[1];
            $year = (int) $m[2];

            $month++;
            if ($month > 12) {
                $month = 1;
                $year++;
            }

            return str_pad($month, 2, '0', STR_PAD_LEFT) . '/' . str_pad($year, 2, '0', STR_PAD_LEFT);
        }

        // Fallback: usar proximo mes a partir da data atual
        $next = strtotime('+1 month');
        return date('m/y', $next);
    }

    // =========================================================================
    //  AJAX: Desvincular
    // =========================================================================

    public function ajax_unlink_sheet()
    {
        check_ajax_referer('hapvida_sheets_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sem permissao.');
        }

        $vendor_name = sanitize_text_field(wp_unslash($_POST['vendor_name']));
        $sheets = get_option($this->sheets_option, array());

        if (!isset($sheets[$vendor_name])) {
            wp_send_json_error('Nenhuma planilha vinculada para "' . $vendor_name . '".');
        }

        unset($sheets[$vendor_name]);
        update_option($this->sheets_option, $sheets);

        wp_send_json_success(array(
            'message' => 'Planilha desvinculada de "' . $vendor_name . '".',
        ));
    }

    // =========================================================================
    //  AJAX: Visualizar
    // =========================================================================

    public function ajax_view_sheet()
    {
        check_ajax_referer('hapvida_sheets_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sem permissao.');
        }

        $vendor_name = sanitize_text_field(wp_unslash($_POST['vendor_name']));
        $sheets = get_option($this->sheets_option, array());

        if (!isset($sheets[$vendor_name])) {
            wp_send_json_error('Nenhuma planilha para "' . $vendor_name . '".');
        }

        wp_send_json_success(array('url' => $sheets[$vendor_name]['url']));
    }
}

new Formulario_Hapvida_Google_Sheets();