<?php
if (!defined('ABSPATH')) exit;

trait ShortcodeFormTrait {

    public function render_dashboard_shortcode($atts)
    {
        // Instancia a classe admin se não existir
        global $formulario_hapvida_admin;
        if (!$formulario_hapvida_admin) {
            $formulario_hapvida_admin = new Formulario_Hapvida_Admin();
        }

        // Chama a função existente render_contagem_shortcode() da classe admin
        return $formulario_hapvida_admin->render_contagem_shortcode();
    }

    public function shortcode_sem_titulo($atts)
    {
        $atts = is_array($atts) ? $atts : array();
        $atts['sem_titulo'] = 'true';
        return $this->shortcode($atts);
    }

    public function shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'form_id' => 'hapvida-form',
            'sem_titulo' => 'false'
        ), $atts);

        $sem_titulo = ($atts['sem_titulo'] === 'true');

        // Obtém as cidades do plugin
        $options = get_option($this->settings_option_name, array());
        $cities_text = isset($options['cidades']) ? $options['cidades'] : '';
        $city_list = array_filter(array_map('trim', explode("\n", $cities_text)));

        ob_start();
        ?>
        <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
        <style>
            /* Reset e base */
            .hapvida-form-container * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            /* ===== GLASSMORPHISM CARD ===== */
            .hapvida-form-container {
                width: 100%;
                max-width: 460px;
                margin: 0 auto 25px;
                font-family: 'Open Sans', sans-serif;
                background: linear-gradient(160deg, rgba(0,84,184,0.18) 0%, rgba(0,84,184,0.08) 25%, rgba(255,255,255,0.45) 50%, rgba(0,84,184,0.1) 70%, rgba(0,84,184,0.2) 100%);
                backdrop-filter: blur(24px);
                -webkit-backdrop-filter: blur(24px);
                border-radius: 24px;
                border: 1px solid rgba(0,84,184,0.15);
                padding: 40px 32px;
                box-shadow: 0 20px 60px rgba(0,84,184,0.1), inset 0 1px 0 rgba(255,255,255,0.7);
                position: relative;
                overflow: hidden;
            }

            /* Decorative blur orbs */
            .hapvida-form-container::before {
                content: '';
                position: absolute;
                width: 220px;
                height: 220px;
                border-radius: 50%;
                background: radial-gradient(circle, rgba(0,84,184,0.2), transparent 70%);
                top: -70px;
                right: -50px;
                filter: blur(40px);
                pointer-events: none;
            }

            .hapvida-form-container::after {
                content: '';
                position: absolute;
                width: 180px;
                height: 180px;
                border-radius: 50%;
                background: radial-gradient(circle, rgba(0,84,184,0.16), transparent 70%);
                bottom: -50px;
                left: -40px;
                filter: blur(35px);
                pointer-events: none;
            }

            /* ===== HEADER ===== */
            .hapvida-form-header {
                text-align: center;
                margin-bottom: 28px;
                position: relative;
                z-index: 1;
            }

            .hapvida-form-title {
                color: #0a2540;
                font-size: 24px;
                font-weight: 900;
                letter-spacing: -0.02em;
                margin-bottom: 8px;
                line-height: 1.4;
            }

            .hapvida-form-subtitle {
                color: #2c4a63;
                font-size: 14px;
                font-weight: 500;
                line-height: 1.5;
            }

            .hap-tag {
                background: #ff6600;
                color: #fff;
                padding: 2px 10px;
                border-radius: 6px;
                display: inline-block;
                transform: skew(-5deg);
                margin: 0 2px;
                line-height: 1.4;
            }

            .hap-tag span {
                display: inline-block;
                transform: skew(5deg);
                font-weight: 700;
            }

            .accent-orange {
                color: #ff6600;
                font-weight: 700;
            }

            /* ===== FORM FIELDS ===== */
            .hapvida-form {
                display: flex;
                flex-direction: column;
                gap: 14px;
                position: relative;
                z-index: 1;
            }

            .hapvida-field-row {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 14px;
            }

            .hapvida-field {
                display: flex;
                align-items: center;
                gap: 12px;
                background: rgba(255,255,255,0.6);
                border: 1.5px solid rgba(0,84,184,0.15);
                border-radius: 14px;
                padding: 0 16px;
                height: 52px;
                transition: all 0.3s ease;
            }

            .hapvida-field:focus-within {
                border-color: #0054B8;
                background: rgba(255,255,255,0.85);
                box-shadow: 0 0 0 3px rgba(0,84,184,0.08);
            }

            .hapvida-field-icon {
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
                color: #0054B8;
                font-size: 16px;
                opacity: 0.9;
            }

            .hapvida-field-icon svg {
                stroke: #0054B8;
            }

            .hapvida-field:focus-within .hapvida-field-icon {
                opacity: 1;
            }

            .hapvida-field input,
            .hapvida-field select {
                flex: 1;
                background: none;
                border: none;
                outline: none;
                color: #0a2540;
                font-size: 14px;
                font-family: 'Open Sans', sans-serif;
                font-weight: 500;
                width: 100%;
                min-width: 0;
                height: auto !important;
                padding: 0 !important;
                box-shadow: none !important;
            }

            .hapvida-field input::placeholder {
                color: rgba(0,84,184,0.45);
            }

            .hapvida-field select {
                appearance: none;
                -webkit-appearance: none;
                cursor: pointer;
                color: rgba(0,84,184,0.45);
            }

            .hapvida-field select option {
                color: #0a2540;
            }

            .hapvida-field select:valid:not([value=""]) {
                color: #0a2540;
            }

            .hapvida-chevron {
                flex-shrink: 0;
            }

            .hapvida-chevron svg {
                stroke: rgba(0,84,184,0.35);
            }

            /* ===== CONTAINER DE IDADES ===== */
            .age-inputs {
                display: grid;
                gap: 10px;
            }

            .age-inputs .hapvida-field {
                display: flex;
                align-items: center;
                gap: 12px;
                background: rgba(255,255,255,0.6);
                border: 1.5px solid rgba(0,84,184,0.1);
                border-radius: 14px;
                padding: 0 16px;
                height: 52px;
                transition: all 0.3s ease;
            }

            .age-inputs .hapvida-field:focus-within {
                border-color: #0054B8;
                background: rgba(255,255,255,0.85);
                box-shadow: 0 0 0 3px rgba(0,84,184,0.08);
            }

            .age-inputs input[name="form_fields[ages][]"] {
                flex: 1 !important;
                width: 100% !important;
                min-width: 0 !important;
                height: auto !important;
                padding: 0 !important;
                border: none !important;
                border-radius: 0 !important;
                font-family: 'Open Sans', sans-serif !important;
                font-size: 14px !important;
                font-weight: 500 !important;
                color: #0a2540 !important;
                background: none !important;
                outline: none !important;
                box-shadow: none !important;
            }

            .age-inputs input[name="form_fields[ages][]"]::placeholder {
                color: rgba(0,84,184,0.45) !important;
                font-weight: 400 !important;
                opacity: 1 !important;
            }

            .age-inputs .hapvida-field-icon {
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                flex-shrink: 0 !important;
                color: #0054B8 !important;
                font-size: 16px !important;
                opacity: 0.9 !important;
                pointer-events: none !important;
            }

            .age-inputs .hapvida-field:focus-within .hapvida-field-icon {
                opacity: 1 !important;
            }

            /* ===== BOTÃO PRINCIPAL ===== */
            .hapvida-submit-btn {
                margin-top: 8px;
                width: 100%;
                height: 54px;
                border: none;
                border-radius: 14px;
                background: linear-gradient(135deg, #0054B8, #0078dc);
                color: #fff;
                font-size: 16px;
                font-weight: 700;
                cursor: pointer;
                font-family: 'Open Sans', sans-serif;
                letter-spacing: 0.04em;
                box-shadow: 0 8px 28px rgba(0,84,184,0.3);
                transition: all 0.25s ease;
                text-transform: uppercase;
                position: relative;
                overflow: hidden;
            }

            .hapvida-submit-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 12px 36px rgba(0,84,184,0.4);
            }

            .hapvida-submit-btn:active {
                transform: translateY(0);
            }

            .hapvida-submit-btn:disabled {
                background: #9ca3af;
                cursor: not-allowed;
                box-shadow: none;
                transform: none;
            }

            .hapvida-submit-btn.loading {
                background: linear-gradient(135deg, #6b7280, #4b5563);
                cursor: wait;
                transform: none;
            }

            .hapvida-submit-btn.loading .hapvida-btn-text {
                opacity: 0;
            }

            .hapvida-submit-btn.loading::after {
                content: '';
                position: absolute;
                width: 20px;
                height: 20px;
                margin: auto;
                border: 3px solid transparent;
                border-top-color: white;
                border-radius: 50%;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
            }

            /* ===== SECURE NOTICE ===== */
            .hapvida-secure-notice {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
                margin-top: 12px;
            }

            .hapvida-secure-notice svg {
                stroke: #0054B8;
                opacity: 0.4;
                flex-shrink: 0;
            }

            .hapvida-secure-notice span {
                color: #8ea4b8;
                font-size: 11px;
                font-weight: 500;
                font-family: 'Open Sans', sans-serif;
            }

            /* ===== VALIDATION STATES ===== */
            .hapvida-field.error {
                border-color: #ef4444 !important;
                background-color: rgba(239, 68, 68, 0.05) !important;
                box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1) !important;
            }

            .hapvida-field.success {
                border-color: #10b981 !important;
                background-color: rgba(16, 185, 129, 0.05) !important;
                box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1) !important;
            }

            /* ========================================
                                           NOVO MODAL DE SUCESSO MELHORADO
                                           ======================================== */
            .hapvida-modal-success {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                z-index: 999999;
                align-items: center;
                justify-content: center;
                opacity: 0;
                visibility: hidden;
                transition: opacity 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94),
                    visibility 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            }

            .hapvida-modal-success.show {
                display: flex;
                opacity: 1;
                visibility: visible;
            }

            /* Overlay com blur elegante */
            .hapvida-modal-success::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: linear-gradient(135deg,
                        rgba(0, 84, 184, 0.95) 0%,
                        rgba(0, 61, 133, 0.95) 50%,
                        rgba(0, 41, 89, 0.95) 100%);
                backdrop-filter: blur(10px);
                -webkit-backdrop-filter: blur(10px);
            }

            /* Container do conteúdo */
            .hapvida-modal-success-content {
                position: relative;
                background: linear-gradient(145deg, #ffffff 0%, #f8fbff 100%);
                border-radius: 20px;
                padding: 0;
                width: 90%;
                max-width: 480px;
                overflow: hidden;
                box-shadow: 0 30px 60px rgba(0, 0, 0, 0.3),
                    0 0 100px rgba(0, 84, 184, 0.2);
                transform: scale(0.8) translateY(30px);
                transition: transform 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            }

            .hapvida-modal-success.show .hapvida-modal-success-content {
                transform: scale(1) translateY(0);
            }

            /* Header do modal */
            .hapvida-modal-success-header {
                background: #0054B8;
                padding: 35px 25px 30px;
                text-align: center;
                position: relative;
                overflow: hidden;
            }

            /* Padrão decorativo no header */
            .hapvida-modal-success-header::before {
                content: '';
                position: absolute;
                top: -50%;
                right: -50%;
                width: 200%;
                height: 200%;
                background: repeating-linear-gradient(45deg,
                        transparent,
                        transparent 10px,
                        rgba(255, 255, 255, 0.03) 10px,
                        rgba(255, 255, 255, 0.03) 20px);
                /*animation: slidePattern 20s linear infinite;*/
            }

            @keyframes slidePattern {
                0% {
                    transform: translate(0, 0);
                }

                100% {
                    transform: translate(50px, 50px);
                }
            }

            /* Ãcone de sucesso animado */
            .hapvida-success-check {
                width: 80px;
                height: 80px;
                margin: 0 auto 20px;
                position: relative;
                z-index: 2;
            }

            .hapvida-success-check-circle {
                width: 80px;
                height: 80px;
                border-radius: 50%;
                background: rgba(255, 255, 255, 0.15);
                border: 3px solid rgba(255, 255, 255, 0.3);
                display: flex;
                align-items: center;
                justify-content: center;
                position: relative;
                /* animation: successPulse 1.5s ease-in-out;*/
            }

            .hapvida-success-check-icon {
                font-size: 40px;
                color: #ffffff;
                /*animation: successScale 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275) 0.2s both;*/
            }

            @keyframes successPulse {
                0% {
                    transform: scale(0);
                    opacity: 0;
                }

                50% {
                    transform: scale(1.1);
                }

                100% {
                    transform: scale(1);
                    opacity: 1;
                }
            }

            @keyframes successScale {
                0% {
                    transform: scale(0) rotate(-45deg);
                    opacity: 0;
                }

                100% {
                    transform: scale(1) rotate(0);
                    opacity: 1;
                }
            }

            /* Título do sucesso */
            .hapvida-modal-success-title {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                font-size: 24px;
                font-weight: 700;
                color: #ffffff;
                margin: 0;
                position: relative;
                z-index: 2;
                letter-spacing: -0.5px;
                text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
                /*animation: fadeInUp 0.6s ease 0.3s both;*/
            }

            @keyframes fadeInUp {
                0% {
                    transform: translateY(20px);
                    opacity: 0;
                }

                100% {
                    transform: translateY(0);
                    opacity: 1;
                }
            }

            /* Botão de fechar elegante */
            .hapvida-modal-success-close {
                position: absolute;
                top: 15px;
                right: 15px;
                width: 36px;
                height: 36px;
                background: rgba(255, 255, 255, 0.1);
                border: 2px solid rgba(255, 255, 255, 0.2);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                transition: all 0.3s ease;
                z-index: 3;
            }

            .hapvida-modal-success-close:hover {
                background: rgba(255, 255, 255, 0.2);
                transform: rotate(90deg) scale(1.1);
                border-color: rgba(255, 255, 255, 0.3);
            }

            .hapvida-modal-success-close i {
                color: #ffffff;
                font-size: 18px;
            }

            /* Body do modal */
            .hapvida-modal-success-body {
                padding: 35px 30px;
                text-align: center;
                background: #ffffff;
                /*animation: fadeIn 0.6s ease 0.4s both;*/
            }

            @keyframes fadeIn {
                0% {
                    opacity: 0;
                }

                100% {
                    opacity: 1;
                }
            }

            /* Mensagem principal */
            .hapvida-success-main-message {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                font-size: 17px;
                color: #1e293b;
                line-height: 1.6;
                margin-bottom: 25px;
            }

            .hapvida-success-main-message strong {
                color: #0054B8;
                font-weight: 600;
            }

            .hapvida-success-highlight {
                color: #10b981 !important;
                font-weight: 700;
            }

            /* Container de redirecionamento com WhatsApp */
            .hapvida-whatsapp-redirect {
                background: #ffffff !important;
                /* Força branco */
                background-image: none !important;
                /* Remove gradiente */
                border: 2px solid #25D366 !important;
                border-radius: 16px;
                padding: 20px;
                margin-top: 20px;
                position: relative;
                overflow: hidden;
            }

            /* Estilo para o link alternativo do WhatsApp */
            #hapvida-whatsapp-link-container {
                animation: slideIn 0.4s ease-out;
            }

            #hapvida-whatsapp-link:hover {
                background: #20ba5a;
                transform: translateY(-2px);
                box-shadow: 0 6px 16px rgba(37, 211, 102, 0.4);
            }

            #hapvida-whatsapp-link:active {
                transform: translateY(0);
            }

            /* Responsivo para mobile */
            @media (max-width: 480px) {
                #hapvida-whatsapp-link {
                    padding: 10px 20px !important;
                    font-size: 14px !important;
                }

                #hapvida-whatsapp-link-container p {
                    font-size: 13px !important;
                }
            }

            @keyframes slideIn {
                0% {
                    transform: translateY(20px);
                    opacity: 0;
                }

                100% {
                    transform: translateY(0);
                    opacity: 1;
                }
            }

            .hapvida-whatsapp-redirect::before {
                display: none;
                /* Remove animação de fundo */
            }

            @keyframes shimmer {
                0% {
                    background-position: 0% 50%;
                }

                50% {
                    background-position: 100% 50%;
                }

                100% {
                    background-position: 0% 50%;
                }
            }

            .hapvida-whatsapp-content {
                position: relative;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 12px;
                font-size: 15px;
                font-weight: 600;
                color: #1b5e20;
            }

            .hapvida-whatsapp-icon {
                font-size: 28px;
                color: #25D366;
                /*animation: bounce 2s infinite;*/
            }

            @keyframes bounce {

                0%,
                20%,
                50%,
                80%,
                100% {
                    transform: translateY(0);
                }

                40% {
                    transform: translateY(-10px);
                }

                60% {
                    transform: translateY(-5px);
                }
            }

            /* Loading dots animados */
            .hapvida-loading-dots {
                display: inline-flex;
                gap: 4px;
                margin-left: 8px;
            }

            .hapvida-loading-dots span {
                width: 8px;
                height: 8px;
                background: #25D366;
                border-radius: 50%;
                /*animation: loadingDot 1.4s ease-in-out infinite;*/
            }


            @keyframes loadingDot {

                0%,
                60%,
                100% {
                    transform: scale(1);
                    opacity: 1;
                }

                30% {
                    transform: scale(1.3);
                    opacity: 0.8;
                }
            }

            /* Timer countdown */
            .hapvida-countdown {
                margin-top: 20px;
                padding: 12px;
                background: rgba(0, 84, 184, 0.08);
                border-radius: 12px;
                font-size: 14px;
                color: #475569;
                font-weight: 500;
            }

            .hapvida-countdown-number {
                display: inline-block;
                min-width: 20px;
                padding: 2px 8px;
                background: #0054B8;
                color: white;
                border-radius: 6px;
                font-weight: 700;
                margin: 0 5px;
                animation: countPulse 1s ease-in-out infinite;
            }

            @keyframes countPulse {

                0%,
                100% {
                    transform: scale(1);
                }

                50% {
                    transform: scale(1.05);
                }
            }

            @keyframes modalZoomIn {
                0% {
                    transform: scale(0.5) translateY(100px);
                    opacity: 0;
                }

                75% {
                    transform: scale(1.02) translateY(-5px);
                }

                100% {
                    transform: scale(1) translateY(0);
                    opacity: 1;
                }
            }

            .hapvida-modal-success.zoom-entrance .hapvida-modal-success-content {
                animation: modalZoomIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            }

            /* Melhorias de acessibilidade */
            .hapvida-field input:focus,
            .hapvida-field select:focus {
                outline: none;
            }

            .hapvida-submit-btn:focus {
                outline: 3px solid rgba(245, 158, 11, 0.4);
                outline-offset: 2px;
            }

            .hapvida-modal-success-close:focus {
                outline: 3px solid rgba(255, 255, 255, 0.5);
                outline-offset: 2px;
            }

            /* Previne scroll quando modal está aberto */
            body.modal-open {
                overflow: hidden;
            }

            /* Responsividade */
            @media (max-width: 768px) {
                .hapvida-form-container {
                    margin: 0 16px 20px;
                    border-radius: 20px;
                    max-width: 95%;
                    padding: 32px 20px;
                }

                .hapvida-form-title {
                    font-size: 20px;
                }

                .hapvida-form-subtitle {
                    font-size: 13px;
                }

                .hapvida-form-header {
                    margin-bottom: 22px;
                }

                .hapvida-field-row {
                    grid-template-columns: 1fr 1fr;
                    gap: 10px;
                }

                .hapvida-field,
                .age-inputs .hapvida-field {
                    height: 48px;
                }

                .hapvida-submit-btn {
                    height: 50px;
                    font-size: 15px;
                    border-radius: 14px;
                }

                /* Modal de sucesso responsivo */
                .hapvida-modal-success-content {
                    width: 95%;
                    margin: 20px;
                    border-radius: 20px;
                }

                .hapvida-modal-success-header {
                    padding: 30px 20px 25px;
                }

                .hapvida-success-check {
                    width: 70px;
                    height: 70px;
                }

                .hapvida-success-check-circle {
                    width: 70px;
                    height: 70px;
                }

                .hapvida-success-check-icon {
                    font-size: 35px;
                }

                .hapvida-modal-success-title {
                    font-size: 23px;
                    color: #ffffff;
                }

                .hapvida-modal-success-body {
                    padding: 25px 20px;
                }

                .hapvida-success-main-message {
                    font-size: 15px;
                }

                .hapvida-whatsapp-redirect {
                    padding: 16px;
                }

                .hapvida-whatsapp-content {
                    font-size: 14px;
                    flex-direction: column;
                    gap: 8px;
                }

                .hapvida-whatsapp-icon {
                    font-size: 24px;
                }
            }

            @media (max-width: 500px) {
                .hapvida-form-container {
                    padding: 32px 20px;
                    border-radius: 20px;
                }

                .hapvida-form-title {
                    font-size: 20px;
                }

                .hapvida-form-subtitle {
                    font-size: 13px;
                }

                .hapvida-field-row {
                    grid-template-columns: 1fr;
                }

                .hapvida-field,
                .age-inputs .hapvida-field {
                    height: 48px;
                    border-radius: 12px;
                }

                .hapvida-submit-btn {
                    height: 48px;
                    font-size: 14px;
                    border-radius: 12px;
                    margin-top: 6px;
                }

                /* Modal de sucesso em mobile pequeno */
                .hapvida-modal-success-content {
                    border-radius: 16px;
                }

                .hapvida-modal-success-header {
                    padding: 25px 16px 20px;
                }

                .hapvida-success-check {
                    width: 60px;
                    height: 60px;
                    margin-bottom: 15px;
                }

                .hapvida-success-check-circle {
                    width: 60px;
                    height: 60px;
                    border-width: 2px;
                }

                .hapvida-success-check-icon {
                    font-size: 30px;
                }

                .hapvida-modal-success-title {
                    font-size: 18px;
                    color: #ffffff;
                }

                .hapvida-modal-success-body {
                    padding: 20px 16px;
                }

                .hapvida-success-main-message {
                    font-size: 14px;
                    margin-bottom: 20px;
                }

                .hapvida-whatsapp-redirect {
                    padding: 14px;
                    border-radius: 12px;
                }

                .hapvida-whatsapp-content {
                    font-size: 13px;
                }

                .hapvida-countdown {
                    font-size: 13px;
                    padding: 10px;
                }
            }
                </style>

                <!-- HTML DO FORMULARIO -->
                <div class="hapvida-form-container no-lazy">
                    <?php if (!$sem_titulo): ?>
                    <div class="hapvida-form-header">
                        <div class="hapvida-form-title">
                            <span class="hap-tag"><span>Faça uma cotação</span></span><br>
                            <span style="color:#0054B8;">em menos de</span> <span class="accent-orange">1 minuto</span>
                        </div>
                        <div class="hapvida-form-subtitle">
                            Apenas assuntos para <span class="hap-tag"><span>CONTRATAÇÃO</span></span><br>
                            de um novo plano Hapvida
                        </div>
                    </div>
                    <?php endif; ?>
                    <form class="hapvida-form no-lazy" id="hapvida-main-form">
                        <!-- Nome Completo -->
                        <div class="hapvida-field" id="hapvida-field-name">
                            <span class="hapvida-field-icon">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                    <circle cx="12" cy="7" r="4"/>
                                </svg>
                            </span>
                            <input type="text" id="hapvida-name" name="form_fields[name]"
                                placeholder="Seu nome completo" required autocomplete="off">
                        </div>

                        <!-- Telefone (WhatsApp) -->
                        <div class="hapvida-field" id="hapvida-field-telefone">
                            <span class="hapvida-field-icon">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413z"/>
                                </svg>
                            </span>
                            <input type="tel" id="hapvida-telefone" name="form_fields[telefone]"
                                data-real-name="form_fields[telefone]"
                                placeholder="(00) 00000-0000" required autocomplete="new-password" readonly="readonly"
                                data-form-type="other" spellcheck="false">
                        </div>

                        <!-- Cidade e Tipo de Plano -->
                        <div class="hapvida-field-row">
                            <div class="hapvida-field" id="hapvida-field-cidade">
                                <span class="hapvida-field-icon">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                                        <circle cx="12" cy="10" r="3"/>
                                    </svg>
                                </span>
                                <select id="hapvida-cidade" name="form_fields[cidade]" required autocomplete="off">
                                    <option value="">Cidade</option>
                                    <?php foreach ($city_list as $city): ?>
                                        <option value="<?php echo esc_attr($city); ?>">
                                            <?php echo esc_html($city); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="hapvida-chevron">
                                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="2.5">
                                        <polyline points="6 9 12 15 18 9"/>
                                    </svg>
                                </span>
                            </div>
                            <div class="hapvida-field" id="hapvida-field-tipo-plano">
                                <span class="hapvida-field-icon">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                        <polyline points="14 2 14 8 20 8"/>
                                        <line x1="12" y1="18" x2="12" y2="12"/>
                                        <line x1="9" y1="15" x2="15" y2="15"/>
                                    </svg>
                                </span>
                                <select id="hapvida-tipo-plano" name="form_fields[qual_plano]" required autocomplete="off">
                                    <option value="">Tipo de Plano</option>
                                    <option value="individual">Individual</option>
                                    <option value="familiar">Familiar</option>
                                    <option value="adesao">Adesão</option>
                                    <option value="empresarial">Empresarial</option>
                                </select>
                                <span class="hapvida-chevron">
                                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="2.5">
                                        <polyline points="6 9 12 15 18 9"/>
                                    </svg>
                                </span>
                            </div>
                        </div>

                        <!-- Quantidade de Pessoas e Idades -->
                        <div class="hapvida-field-row">
                            <div class="hapvida-field" id="hapvida-field-qtd-pessoas">
                                <span class="hapvida-field-icon">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                        <circle cx="9" cy="7" r="4"/>
                                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                                    </svg>
                                </span>
                                <input type="number" id="hapvida-qtd-pessoas" name="form_fields[qtd_pessoas]"
                                    placeholder="Nº Pessoas" value="1" min="1" max="20" required autocomplete="off">
                            </div>
                            <div class="age-inputs no-lazy" id="hapvida-age-inputs">
                                <div class="hapvida-field">
                                    <span class="hapvida-field-icon">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                            <line x1="16" y1="2" x2="16" y2="6"/>
                                            <line x1="8" y1="2" x2="8" y2="6"/>
                                            <line x1="3" y1="10" x2="21" y2="10"/>
                                        </svg>
                                    </span>
                                    <input type="number" name="form_fields[ages][]"
                                        placeholder="Idade 1" min="0" max="120" required autocomplete="off">
                                </div>
                            </div>
                        </div>

                        <!-- Campos ocultos -->
                        <input type="hidden" name="form_fields[data]" value="<?php echo date('Y-m-d H:i:s'); ?>">
                        <input type="hidden" name="form_fields[atendente]" value="">

                        <!-- Botão de Envio -->
                        <button type="submit" class="hapvida-submit-btn no-lazy" id="hapvida-submit-btn">
                            <span class="hapvida-btn-text">Solicitar Cotação</span>
                        </button>

                        <!-- Secure Notice -->
                        <div class="hapvida-secure-notice">
                            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="2.5">
                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                                <path d="M9 12l2 2 4-4"/>
                            </svg>
                            <span>Fique tranquilo, seus dados estão seguros</span>
                        </div>
                    </form>
                </div>

                <!-- NOVO MODAL DE SUCESSO MELHORADO -->
                <div id="hapvida-success-modal" class="hapvida-modal-success no-lazy">
                    <div class="hapvida-modal-success-content">
                        <div class="hapvida-modal-success-header">
                            <div class="hapvida-success-check">
                                <div class="hapvida-success-check-circle">
                                    <i class="fas fa-check hapvida-success-check-icon no-lazy"></i>
                                </div>
                            </div>
                            <h3 class="hapvida-modal-success-title" style="color: white;">
                                Formulário Enviado com Sucesso!
                            </h3>

                            <button type="button" class="hapvida-modal-success-close no-lazy" id="hapvida-modal-close">
                                <i class="fas fa-times no-lazy"></i>
                            </button>
                        </div>

                        <div class="hapvida-modal-success-body">
                            <p class="hapvida-success-main-message">
                                <strong>Parabéns!</strong> Seus dados foram recebidos.<br>
                                Você <span class="hapvida-success-highlight">receberá sua cotação</span>
                                através de um dos nossos consultores especializados em instantes!
                            </p>

                            <div class="hapvida-whatsapp-redirect">
                                <div class="hapvida-whatsapp-content">
                                    <i class="fab fa-whatsapp hapvida-whatsapp-icon no-lazy"></i>
                                    <span id="hapvida-redirect-text">
                                        Redirecionando para WhatsApp
                                        <span class="hapvida-loading-dots">
                                            <span></span>
                                            <span></span>
                                            <span></span>
                                        </span>
                                    </span>
                                </div>

                                <!-- NOVO: Link clicável para WhatsApp -->
                                <div id="hapvida-whatsapp-link-container" style="display: none; margin-top: 15px; text-align: center;">
                                    <p style="font-size: 14px; color: #666; margin-bottom: 10px;">
                                        <strong>Não redirecionou automaticamente?</strong><br>
                                        Clique no botão abaixo para falar com nosso consultor:
                                    </p>
                                    <a href="#" id="hapvida-whatsapp-link" target="_blank" style="display: inline-block; background: #25D366; color: white; 
                              padding: 12px 24px; border-radius: 12px; text-decoration: none; 
                              font-weight: 600; font-size: 15px; transition: all 0.3s ease;
                              box-shadow: 0 4px 12px rgba(37, 211, 102, 0.3);">
                                        <i class="fab fa-whatsapp" style="margin-right: 8px;"></i>
                                        Abrir WhatsApp
                                    </a>
                                </div>
                            </div>

                            <div class="hapvida-countdown">
                                Redirecionamento em <span class="hapvida-countdown-number">5</span> segundos
                            </div>
                        </div>
                    </div>
                </div>

                <script>
                            (function ($) {
                                   'use strict';

                                // Evita múltipla inicialização
                                if (window.hapvidaPopupReady) return;
                                window.hapvidaPopupReady = true;

                                var isSubmitted = false;

                                // ====================================================================
                                // NOVA FUNÇÃO DO MODAL DE SUCESSO MELHORADO
                                // ====================================================================
                                window.showSuccessModal = function (redirectUrl) {
                                    console.log('Mostrando modal de sucesso melhorado');

                                    // Remove qualquer modal anterior
                                    $('.hapvida-modal-success').removeClass('show zoom-entrance');

                                    // Adiciona classe ao body para prevenir scroll
                                    $('body').addClass('modal-open');

                                    // Pega o modal
                                    var $modal = $('#hapvida-success-modal');

                                    // Verifica se o modal existe
                                    if ($modal.length === 0) {
                                        console.error('âŒ Modal de sucesso não encontrado no DOM');
                                        // Fallback: redireciona direto
                                        if (redirectUrl) {
                                            setTimeout(function () {
                                                window.open(redirectUrl, '_blank');
                                            }, 500);
                                        }
                                        return;
                                    }

                                    // Configura o link do WhatsApp
                                    $('#hapvida-whatsapp-link').attr('href', redirectUrl);

                                    // Adiciona classes para mostrar com animação
                                    setTimeout(function () {
                                        $modal.addClass('show zoom-entrance');
                                    }, 10);

                                    // Configuração do countdown
                                    var countdown = 2;
                                    var $countdownNumber = $('.hapvida-countdown-number');
                                    var popupBlocked = false;

                                    // Limpa qualquer interval anterior
                                    if (window.hapvidaCountdownInterval) {
                                        clearInterval(window.hapvidaCountdownInterval);
                                    }

                                    // Atualiza countdown
                                    window.hapvidaCountdownInterval = setInterval(function () {
                                        countdown--;

                                        if (countdown > 0) {
                                            $countdownNumber.text(countdown);

                                            // Adiciona efeito de pulse no número
                                            $countdownNumber.css('transform', 'scale(1.2)');
                                            setTimeout(function () {
                                                $countdownNumber.css('transform', 'scale(1)');
                                            }, 200);
                                        } else {
                                            // Para o countdown
                                            clearInterval(window.hapvidaCountdownInterval);

                                            // Tenta redirecionar para WhatsApp
                                            if (redirectUrl) {
                                                console.log('Tentando abrir WhatsApp:', redirectUrl);

                                                // Tenta abrir o popup
                                                var newWindow = window.open(redirectUrl, '_blank');

                                                // Detecta se o popup foi bloqueado
                                                setTimeout(function () {
                                                    try {
                                                        if (!newWindow || newWindow.closed || typeof newWindow.closed === 'undefined') {
                                                            // Popup foi bloqueado
                                                            popupBlocked = true;
                                                            console.log('Popup bloqueado - mostrando link alternativo');

                                                            // Mostra mensagem alternativa
                                                            $('#hapvida-redirect-text').html(
                                                                '<i class="fas fa-exclamation-circle" style="color: #f59e0b; margin-right: 8px;"></i>' +
                                                                '<strong style="color: #dc2626;">Popup bloqueado pelo navegador</strong>'
                                                            );

                                                            // Esconde o countdown
                                                            $('.hapvida-countdown').fadeOut(300);

                                                            // Mostra o link clicável
                                                            $('#hapvida-whatsapp-link-container').slideDown(400);

                                                            // NÃO fecha o modal - mantém aberto para o usuário clicar
                                                            console.log('✅ Modal mantido aberto para clique manual');

                                                        } else {
                                                            // Popup abriu com sucesso
                                                            console.log('✅ WhatsApp aberto com sucesso');

                                                            // Fecha o modal após redirecionamento bem-sucedido
                                                            setTimeout(function () {
                                                                closeSuccessModal();
                                                            }, 5000);
                                                        }
                                                    } catch (e) {
                                                        // Erro ao verificar popup - assume bloqueio
                                                        popupBlocked = true;
                                                        console.log('âš ï¸ Erro ao verificar popup - assumindo bloqueio');

                                                        $('#hapvida-redirect-text').html(
                                                            '<i class="fas fa-exclamation-circle" style="color: #f59e0b; margin-right: 8px;"></i>' +
                                                            '<strong style="color: #dc2626;">Não foi possível abrir automaticamente</strong>'
                                                        );

                                                        $('.hapvida-countdown').fadeOut(300);
                                                        $('#hapvida-whatsapp-link-container').slideDown(400);
                                                    }
                                                }, 100);
                                            }
                                        }
                                    }, 1000);

                                    // Som de sucesso (opcional)
                                    try {
                                        playSuccessSound();
                                    } catch (e) {
                                        // Ignora erro de áudio
                                    }

                                    // Vibração no mobile (opcional)
                                    if ('vibrate' in navigator) {
                                        navigator.vibrate([100, 50, 100]);
                                    }
                                };

                                // Função para fechar o modal de sucesso
                                window.closeSuccessModal = function () {
                                    console.log('Fechando modal de sucesso');

                                    var $modal = $('#hapvida-success-modal');

                                    // Para o countdown se estiver rodando
                                    if (window.hapvidaCountdownInterval) {
                                        clearInterval(window.hapvidaCountdownInterval);
                                    }

                                    // Remove classes
                                    $modal.removeClass('show');
                                    $('body').removeClass('modal-open');

                                    // Remove completamente após animação
                                    setTimeout(function () {
                                        $modal.removeClass('zoom-entrance');
                                        // Reset do countdown
                                        $('.hapvida-countdown-number').text('3');
                                    }, 400);
                                };

                                // Som de sucesso (opcional)
                                function playSuccessSound() {
                                    var audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+DyvmwhBSl+zPPTgjMGHm7A7+OZURE');
                                    audio.volume = 0.1;
                                    audio.play().catch(function () {
                                        // Ignora erro se autoplay for bloqueado
                                    });
                                }

                                // ====================================================================
                                // VALIDAÇÃO DE TELEFONE SIMPLIFICADA
                                // ====================================================================
                                function validatePhoneNumber(phone) {
                                    const cleanPhone = phone.replace(/\D/g, '');

                                    if (cleanPhone.length >= 10 && cleanPhone.length <= 11) {
                                        const ddd = cleanPhone.substring(0, 2);

                                        if (parseInt(ddd) >= 11 && parseInt(ddd) <= 99) {
                                            return {
                                                valid: true,
                                                format: cleanPhone.length === 11 ? 'celular' : 'residencial',
                                                clean: cleanPhone
                                            };
                                        }
                                    }

                                    return { valid: false, format: null, clean: cleanPhone };
                                }

                                function getPhoneValidationMessage(phoneResult) {
                                    if (phoneResult.valid) return '';

                                    const length = phoneResult.clean.length;

                                    if (length === 0) {
                                        return 'Por favor, digite seu número de telefone';
                                    } else if (length < 10) {
                                        return `Número muito curto. Digite o DDD + número (faltam ${10 - length} dígitos)`;
                                    } else if (length > 11) {
                                        return `Número muito longo (${length} dígitos). Máximo: 11 dígitos`;
                                    } else {
                                        const ddd = phoneResult.clean.substring(0, 2);
                                        if (parseInt(ddd) < 11 || parseInt(ddd) > 99) {
                                            return 'DDD inválido. Use um DDD entre 11 e 99';
                                        }
                                    }

                                    return 'Formato inválido. Use: (DD) XXXX-XXXX';
                                }

                                function formatPhoneDisplay(phone) {
                                    const clean = phone.replace(/\D/g, '');

                                    if (clean.length >= 10) {
                                        if (clean.length === 10) {
                                            return `(${clean.substring(0, 2)}) ${clean.substring(2, 6)}-${clean.substring(6)}`;
                                        } else if (clean.length === 11) {
                                            return `(${clean.substring(0, 2)}) ${clean.substring(2, 7)}-${clean.substring(7)}`;
                                        }
                                    }

                                    return phone;
                                }

                                // ====================================================================
                                // MENSAGENS DE ERRO MELHORADAS
                                // ====================================================================
                                function getImprovedErrorMessage(errorMessage) {
                                    if (errorMessage.includes('telefone já enviou um formulário') ||
                                        errorMessage.includes('já foi processado recentemente') ||
                                        errorMessage.includes('já está sendo processado')) {

                                        return {
                                            title: 'Formulario Ja Enviado',
                                            message: `
                    <div style="text-align: center; padding: 20px; font-family: Arial, sans-serif;">
                        <h3 style="color: #0054B8; margin-bottom: 15px;">
                            Seus dados ja foram enviados com sucesso!
                        </h3>
                        <p style="font-size: 16px; color: #333; line-height: 1.5; margin-bottom: 20px;">
                            <strong>Nao se preocupe!</strong> Suas informacoes ja estao com nossa equipe de consultores.
                        </p>
                        <div style="background: #f8f9ff; border: 2px solid #0054B8; border-radius: 12px; padding: 20px; margin: 20px 0;">
                            <p style="margin: 0; color: #0054B8; font-weight: bold; font-size: 18px;">
                                Em instantes, um de nossos consultores especializados entrara em contato pelo WhatsApp!
                            </p>
                        </div>
                        <p style="font-size: 14px; color: #666; margin-bottom: 15px;">
                            <strong>Tempo medio de resposta:</strong> 5 a 15 minutos
                        </p>
                        <p style="font-size: 14px; color: #666;">
                            Se nao receber contato em 30 minutos, pode enviar o formulario novamente.
                        </p>
                    </div>
                `,
                                            type: 'info'
                                        };
                                    }

                                    return {
                                        title: 'Erro',
                                        message: `<div style="text-align: center; padding: 20px;">${errorMessage}</div>`,
                                        type: 'error'
                                    };
                                }

                                function showImprovedModal(config) {
                                    const { title, message, type = 'info' } = config;

                                    const colors = {
                                        'info': { bg: '#0054B8', text: '#ffffff' },
                                        'error': { bg: '#dc3545', text: '#ffffff' },
                                        'warning': { bg: '#ffc107', text: '#000000' },
                                        'success': { bg: '#28a745', text: '#ffffff' }
                                    };

                                    const color = colors[type] || colors.info;

                                    // Remove modal anterior
                                    $('#hapvida-improved-modal').remove();

                                    const modalHtml = `
            <div id="hapvida-improved-modal" class="hapvida-modal-success show no-lazy" style="z-index: 999999;">
                <div class="hapvida-modal-success-content" style="max-width: 500px;">
                    <div class="hapvida-modal-success-header" style="background: ${color.bg}; color: ${color.text};">
                        <h3 style="margin: 0; font-size: 18px;">${title}</h3>
                        <button type="button" class="hapvida-modal-success-close hapvida-close-btn no-lazy">
                            <i class="fas fa-times no-lazy"></i>
                        </button>
                    </div>
                    <div class="hapvida-modal-success-body">
                        ${message}
                        <div style="text-align: center; margin-top: 20px;">
                            <button type="button" class="hapvida-close-btn no-lazy" 
                                    style="background: ${color.bg}; color: ${color.text}; border: none; padding: 12px 30px; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 14px;">
                                Entendi
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;

                                    $('body').append(modalHtml);

                                    // Event delegation para fechar modal
                                    $(document).off('click.hapvidaModal').on('click.hapvidaModal', '.hapvida-close-btn', function (e) {
                                        e.preventDefault();
                                        e.stopPropagation();
                                        $('#hapvida-improved-modal').remove();
                                    });

                                    // Fechar clicando fora do modal
                                    $(document).off('click.hapvidaModalBg').on('click.hapvidaModalBg', '#hapvida-improved-modal', function (e) {
                                        if (e.target === this) {
                                            $(this).remove();
                                        }
                                    });

                                    // Fechar com ESC
                                    $(document).off('keydown.hapvidaModal').on('keydown.hapvidaModal', function (e) {
                                        if (e.keyCode === 27) {
                                            $('#hapvida-improved-modal').remove();
                                        }
                                    });
                                }

                                // ====================================================================
                                // SETUP DO CAMPO DE TELEFONE
                                // ====================================================================
                                $(document).on('focus', '#hapvida-telefone', function () {
                                    $(this).removeAttr('readonly');
                                });

                                function setupPhoneFormatting() {
                                    $(document).on('input', '#hapvida-telefone', function () {
                                        let value = $(this).val().replace(/\D/g, '');
                                        let formatted = '';

                                        if (value.length >= 2) {
                                            formatted = `(${value.substring(0, 2)}`;

                                            if (value.length > 2) {
                                                if (value.length <= 6) {
                                                    formatted += `) ${value.substring(2)}`;
                                                } else if (value.length <= 10) {
                                                    formatted += `) ${value.substring(2, 6)}-${value.substring(6)}`;
                                                } else if (value.length === 11) {
                                                    formatted += `) ${value.substring(2, 7)}-${value.substring(7)}`;
                                                } else {
                                                    value = value.substring(0, 11);
                                                    formatted += `) ${value.substring(2, 7)}-${value.substring(7)}`;
                                                }
                                            }
                                        } else {
                                            formatted = value;
                                        }

                                        $(this).val(formatted);
                                        $(this).closest('.hapvida-field').removeClass('error');
                                        $(this).closest('.hapvida-field').next('.phone-error-message').remove();
                                    });

                                    $(document).on('focus', '#hapvida-telefone', function () {
                                        if (!$(this).val()) {
                                            $(this).attr('placeholder', '(11) 1234-5678');
                                        }
                                    });

                                    $(document).on('blur', '#hapvida-telefone', function () {
                                        if (!$(this).val()) {
                                            $(this).attr('placeholder', '(00) 00000-0000');
                                        }
                                    });
                                }

                                function preventPhoneAutofill() {
                                    $(document).on('focus blur change', '#hapvida-telefone', function () {
                                        var $field = $(this);

                                        if ($field.val() && !$field.data('user-typed')) {
                                            setTimeout(function () {
                                                $field.val('').removeAttr('readonly');
                                                $field.attr('placeholder', 'Digite seu WhatsApp');
                                            }, 100);
                                        }
                                    });

                                    $(document).on('keydown input', '#hapvida-telefone', function () {
                                        $(this).data('user-typed', true);
                                    });

                                    setTimeout(function () {
                                        var $phone = $('#hapvida-telefone');
                                        if ($phone.length && $phone.val() && !$phone.data('user-typed')) {
                                            $phone.val('').removeAttr('readonly');
                                        }
                                    }, 1000);
                                }

                                // ====================================================================
                                // EVENT DELEGATION GLOBAL - FUNCIONA COM POPUPS
                                // ====================================================================
                                $(document).on('submit', '#hapvida-main-form', function (e) {
                                    e.preventDefault();

                                    if (isSubmitted) {
                                        return false;
                                    }

                                    var $form = $(this);

                                    if (!validateFormImproved($form)) {
                                        return false;
                                    }

                                    isSubmitted = true;
                                    submitFormImproved($form);
                                });

                                $(document).on('change input', '#hapvida-qtd-pessoas, [name="form_fields[qtd_pessoas]"]', function () {
                                    updateAgeFields($(this));
                                });

                                // Event listeners para o novo modal de sucesso
                                $(document).on('click', '#hapvida-modal-close', function (e) {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    closeSuccessModal();
                                });

                                $(document).on('click', '#hapvida-success-modal', function (e) {
                                    if ($(e.target).hasClass('hapvida-modal-success')) {
                                        closeSuccessModal();
                                    }
                                });

                                // Fechar com ESC
                                $(document).keyup(function (e) {
                                    if (e.key === "Escape" && $('#hapvida-success-modal').hasClass('show')) {
                                        closeSuccessModal();
                                    }
                                });

                                // Observer para detectar formulários em popups
                                var observer = new MutationObserver(function (mutations) {
                                    mutations.forEach(function (mutation) {
                                        if (mutation.type === 'childList') {
                                            mutation.addedNodes.forEach(function (node) {
                                                if (node.nodeType === 1) {
                                                    var $forms = $(node).find('#hapvida-main-form, form[id*="hapvida"]');
                                                    if ($forms.length) {
                                                        $forms.each(function () {
                                                            var $form = $(this);
                                                            if (!$form.data('popup-ready')) {
                                                                initializeForm($form);

                                                                setTimeout(function () {
                                                                    var $qtdField = $form.find('#hapvida-qtd-pessoas, [name="form_fields[qtd_pessoas]"]');
                                                                    if ($qtdField.length) {
                                                                        updateAgeFields($qtdField);
                                                                    }
                                                                }, 100);
                                                            }
                                                        });
                                                    }

                                                    if ((node.id === 'hapvida-main-form' || node.tagName === 'FORM') &&
                                                        !$(node).data('popup-ready')) {
                                                        initializeForm($(node));

                                                        setTimeout(function () {
                                                            var $qtdField = $(node).find('#hapvida-qtd-pessoas, [name="form_fields[qtd_pessoas]"]');
                                                            if ($qtdField.length) {
                                                                updateAgeFields($qtdField);
                                                            }
                                                        }, 100);
                                                    }
                                                }
                                            });
                                        }
                                    });
                                });

                                observer.observe(document.body, {
                                    childList: true,
                                    subtree: true
                                });

                                // ====================================================================
                                // INICIALIZAÇÃO DE FORMULÃRIO
                                // ====================================================================
                                function initializeForm($form) {
                                    $form.data('popup-ready', true);

                                    var $qtdPessoas = $form.find('#hapvida-qtd-pessoas');
                                    if (!$qtdPessoas.length) {
                                        $qtdPessoas = $form.find('[name="form_fields[qtd_pessoas]"]');
                                    }

                                    if ($qtdPessoas.length) {
                                        if ($qtdPessoas.val()) {
                                            updateAgeFields($qtdPessoas);
                                        } else {
                                            $qtdPessoas.val('1');
                                            updateAgeFields($qtdPessoas);
                                        }
                                    }
                                }

                                // ====================================================================
                                // VALIDAÇÃO MELHORADA DO FORMULÃRIO
                                // ====================================================================
                                function validateFormImproved($form) {
                                    let isValid = true;
                                    let firstErrorField = null;

                                    $form.find('[required]').each(function () {
                                        const $field = $(this);
                                        const fieldValue = $field.val().trim();

                                        $field.closest('.hapvida-field').removeClass('error');

                                        if (!fieldValue) {
                                            $field.closest('.hapvida-field').addClass('error');
                                            if (!firstErrorField) firstErrorField = $field;
                                            isValid = false;
                                        }
                                    });

                                    const $phoneField = $form.find('#hapvida-telefone');
                                    if ($phoneField.length) {
                                        const phoneValue = $phoneField.val().trim();
                                        const phoneResult = validatePhoneNumber(phoneValue);

                                        if (!phoneResult.valid) {
                                            $phoneField.closest('.hapvida-field').addClass('error');
                                            const message = getPhoneValidationMessage(phoneResult);

                                            $phoneField.closest('.hapvida-field').find('.phone-error-message').remove();

                                            const errorHtml = `
                    <div class="phone-error-message" style="
                        color: #dc3545; 
                        font-size: 12px; 
                        margin-top: 5px; 
                        padding: 8px 12px;
                        background: rgba(220, 53, 69, 0.1);
                        border-radius: 8px;
                        border-left: 3px solid #dc3545;
                    ">
                        <i class="fas fa-exclamation-triangle" style="margin-right: 8px;"></i>
                        ${message}
                    </div>
                `;
                                            $phoneField.closest('.hapvida-field').after(errorHtml);

                                            if (!firstErrorField) firstErrorField = $phoneField;
                                            isValid = false;
                                        } else {
                                            $phoneField.closest('.hapvida-field').next('.phone-error-message').remove();

                                            const formattedPhone = formatPhoneDisplay(phoneValue);
                                            if (formattedPhone !== phoneValue) {
                                                $phoneField.val(formattedPhone);
                                            }
                                        }
                                    }

                                    if (!isValid && firstErrorField) {
                                        firstErrorField.focus();

                                        if (firstErrorField.offset()) {
                                            $('html, body').animate({
                                                scrollTop: firstErrorField.offset().top - 100
                                            }, 300);
                                        }
                                    }

                                    if (isValid) {
                                        const qtdPessoas = parseInt($form.find('#hapvida-qtd-pessoas').val()) || 1;
                                        let filledAges = 0;

                                        $form.find('[name="form_fields[ages][]"]').each(function () {
                                            if ($(this).val().trim()) filledAges++;
                                        });

                                        if (filledAges < qtdPessoas) {
                                            showImprovedModal({
                                                title: 'Idades Incompletas',
                                                message: `
                        <div style="text-align: center; padding: 20px;">
                            <div style="font-size: 48px; color: #ffc107; margin-bottom: 15px;">âš ï¸</div>
                            <h3 style="color: #ffc107; margin-bottom: 15px;">Informação Incompleta</h3>
                            <p style="font-size: 16px; margin-bottom: 20px;">
                                Você selecionou <strong>${qtdPessoas} pessoa(s)</strong>, 
                                mas preencheu apenas <strong>${filledAges} idade(s)</strong>.
                            </p>
                            <div style="background: #fff3cd; border: 2px solid #ffc107; border-radius: 12px; padding: 15px;">
                                <p style="margin: 0; color: #856404; font-weight: bold;">
                                    Por favor, preencha a idade de todas as pessoas para uma cotação precisa!
                                </p>
                            </div>
                        </div>
                    `,
                                                type: 'warning'
                                            });
                                            isValid = false;
                                        }
                                    }

                                    return isValid;
                                }

                                // ====================================================================
                                // ENVIO MELHORADO DO FORMULÃRIO COM NOVO MODAL
                                // ====================================================================
                                function submitFormImproved($form) {
                                    var $btn = $form.find('#hapvida-submit-btn');
                                    var originalText = $btn.html();

                                    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Enviando...');

                                    var formData = {};

                                    $form.serializeArray().forEach(function (field) {
                                        if (field.name.includes('[]')) {
                                            var cleanName = field.name.replace('[]', '');
                                            if (!formData[cleanName]) {
                                                formData[cleanName] = [];
                                            }
                                            if (field.value && field.value.trim() !== '') {
                                                formData[cleanName].push(field.value.trim());
                                            }
                                        } else {
                                            formData[field.name] = field.value;
                                        }
                                    });

                                    const phoneField = formData['form_fields[telefone]'];
                                    if (phoneField) {
                                        formData['form_fields[telefone]'] = phoneField.replace(/\D/g, '');
                                    }

                                    // Envia o formulário
                                    $.ajax({
                                        url: '<?php echo esc_url_raw(rest_url('formulario-hapvida/v1/submit-form')); ?>',
                                        method: 'POST',
                                        data: formData,
                                        timeout: 30000,

                                        success: function (response) {
                                            if (response.success && response.whatsapp_url) {
                                                console.log('✅ Formulário enviado com sucesso, redirecionando...');

                                                <?php
                                                $plugin_options = get_option('formulario_hapvida_settings', array());
                                                $redirect_obrigado = isset($plugin_options['redirect_obrigado']) && $plugin_options['redirect_obrigado'] === '1';
                                                ?>

                                                var redirectObrigado = <?php echo $redirect_obrigado ? 'true' : 'false'; ?>;

                                                if (redirectObrigado) {
                                                    // Armazena URL do WhatsApp no sessionStorage
                                                    sessionStorage.setItem('hapvida_whatsapp_url', response.whatsapp_url);

                                                    // Armazena informações do vendedor
                                                    if (response.vendor_info) {
                                                        sessionStorage.setItem('hapvida_vendor_info', JSON.stringify(response.vendor_info));
                                                    }

                                                    // Redireciona para página de obrigado COM URL do WhatsApp como parâmetro GET
                                                    var thankYouUrl = 'https://tabelaplanos.com.br/obrigado/?whatsapp=' + encodeURIComponent(response.whatsapp_url);
                                                    window.location.href = thankYouUrl;
                                                } else {
                                                    // Redireciona direto para o WhatsApp do vendedor
                                                    window.location.href = response.whatsapp_url;
                                                }

                                            } else {
                                                handleImprovedError(response.message || 'Erro desconhecido');
                                            }

                                            $btn.prop('disabled', false).html(originalText);
                                            setTimeout(function () {
                                                isSubmitted = false;
                                            }, 2000);
                                        },

                                        error: function (xhr, status, error) {
                                            let serverMessage = 'Erro de conexão. Tente novamente.';
                                            if (xhr.responseJSON && xhr.responseJSON.message) {
                                                serverMessage = xhr.responseJSON.message;
                                            }

                                            handleImprovedError(serverMessage);
                                            $btn.prop('disabled', false).html(originalText);
                                            setTimeout(function () {
                                                isSubmitted = false;
                                            }, 2000);
                                        }
                                    });
                                }

                                // ====================================================================
                                // TRATAMENTO DE ERRO
                                // ====================================================================
                                function handleImprovedError(errorMessage) {
                                    const improvedError = getImprovedErrorMessage(errorMessage);
                                    showImprovedModal(improvedError);

                                    isSubmitted = false;
                                }

                                // ====================================================================
                                // ATUALIZAR CAMPOS DE IDADE
                                // ====================================================================
                                function updateAgeFields($qtdInput) {
                                    var qtd = parseInt($qtdInput.val()) || 1;

                                    var $container = $qtdInput.closest('form').find('#hapvida-age-inputs');

                                    if (!$container.length) {
                                        $container = $qtdInput.closest('form').find('.age-inputs');
                                    }

                                    if (!$container.length) {
                                        $container = $('#hapvida-age-inputs');
                                        if (!$container.length) {
                                            $container = $('.age-inputs');
                                        }
                                    }

                                    if (!$container.length) {
                                        return;
                                    }

                                    $container.empty();

                                    for (var i = 1; i <= qtd; i++) {
                                        var $wrapper = $('<div class="hapvida-field">');
                                        var ageSvg = '<span class="hapvida-field-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>';
                                        $wrapper.append(ageSvg);
                                        var $input = $('<input type="number" name="form_fields[ages][]" min="0" max="120" required autocomplete="off">');
                                        $input.attr('placeholder', 'Idade ' + i);
                                        $wrapper.append($input);
                                        $container.append($wrapper);
                                    }
                                }

                                // ====================================================================
                                // INICIALIZAÇÃO COMPLETA
                                // ====================================================================
                                $(document).ready(function () {
                                    setupPhoneFormatting();
                                    preventPhoneAutofill();

                                    var $existingForm = $('#hapvida-main-form');
                                    if ($existingForm.length) {
                                        initializeForm($existingForm);
                                    }

                                    var attempts = 0;
                                    var maxAttempts = 50;

                                    var polling = setInterval(function () {
                                        attempts++;
                                        var $forms = $('#hapvida-main-form, form[id*="hapvida"]');

                                        $forms.each(function () {
                                            var $form = $(this);
                                            if (!$form.data('popup-ready')) {
                                                initializeForm($form);

                                                setTimeout(function () {
                                                    var $qtdField = $form.find('#hapvida-qtd-pessoas, [name="form_fields[qtd_pessoas]"]');
                                                    if ($qtdField.length) {
                                                        $qtdField.val('1');
                                                        updateAgeFields($qtdField);
                                                    }
                                                }, 200);
                                            }
                                        });

                                        if ($forms.length > 0 || attempts >= maxAttempts) {
                                            clearInterval(polling);
                                        }
                                    }, 100);
                                });

                            })(jQuery);
                        </script>

                        <?php
                        return ob_get_clean();
    }

}
