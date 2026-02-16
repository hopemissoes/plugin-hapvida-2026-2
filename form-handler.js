/**
 * Form Handler - Redirecionamento WhatsApp na Página de Obrigado
 *
 * Este arquivo deve ser adicionado à página https://tabelaplanos.com.br/obrigado/
 *
 * Adicione antes do </body>:
 * <script src="caminho/para/form-handler.js"></script>
 */

(function() {
    'use strict';

    console.log('📋 Form Handler carregado');

    // Aguarda o DOM estar pronto
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWhatsAppRedirect);
    } else {
        initWhatsAppRedirect();
    }

    function initWhatsAppRedirect() {
        console.log('🚀 Iniciando verificação de redirecionamento WhatsApp');

        // MÉTODO 1: Tenta ler do sessionStorage
        var whatsappUrl = sessionStorage.getItem('hapvida_whatsapp_url');

        // MÉTODO 2: Tenta ler da URL (parâmetro GET)
        if (!whatsappUrl) {
            var urlParams = new URLSearchParams(window.location.search);
            whatsappUrl = urlParams.get('whatsapp');
        }

        if (whatsappUrl) {
            console.log('✅ URL do WhatsApp encontrada:', whatsappUrl);

            // Decodifica a URL se necessário
            try {
                whatsappUrl = decodeURIComponent(whatsappUrl);
            } catch(e) {
                console.warn('⚠️ Erro ao decodificar URL:', e);
            }

            // Cria elemento de redirecionamento visual (opcional)
            createRedirectUI(whatsappUrl);

            // Tenta abrir WhatsApp imediatamente
            redirectToWhatsApp(whatsappUrl);

            // Limpa o sessionStorage
            sessionStorage.removeItem('hapvida_whatsapp_url');
            sessionStorage.removeItem('hapvida_vendor_info');

        } else {
            console.log('ℹ️ Nenhuma URL do WhatsApp encontrada - visitante direto da página');
        }
    }

    function redirectToWhatsApp(url) {
        console.log('📱 Tentando abrir WhatsApp:', url);

        // Tenta abrir em nova aba
        var newWindow = window.open(url, '_blank');

        // Verifica se o popup foi bloqueado
        setTimeout(function() {
            try {
                if (!newWindow || newWindow.closed || typeof newWindow.closed === 'undefined') {
                    console.warn('🚫 Popup bloqueado - mostrando link alternativo');
                    showFallbackLink(url);
                } else {
                    console.log('✅ WhatsApp aberto com sucesso');
                }
            } catch(e) {
                console.warn('⚠️ Erro ao verificar popup:', e);
                showFallbackLink(url);
            }
        }, 500);
    }

    function createRedirectUI(url) {
        // Verifica se já existe
        if (document.getElementById('hapvida-redirect-container')) {
            return;
        }

        // Cria container de redirecionamento
        var container = document.createElement('div');
        container.id = 'hapvida-redirect-container';
        container.style.cssText = 'position: fixed; top: 20px; right: 20px; background: #25D366; color: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 9999; max-width: 300px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;';

        container.innerHTML = `
            <div style="display: flex; align-items: center; gap: 12px;">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="white">
                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                </svg>
                <div>
                    <div style="font-weight: 600; font-size: 16px; margin-bottom: 4px;">
                        Abrindo WhatsApp...
                    </div>
                    <div id="hapvida-redirect-status" style="font-size: 13px; opacity: 0.9;">
                        Aguarde um momento
                    </div>
                </div>
            </div>
            <div id="hapvida-fallback-link" style="display: none; margin-top: 12px; text-align: center;">
                <a href="${url}" target="_blank" style="display: inline-block; background: white; color: #25D366; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px;">
                    🔗 Clique para Abrir WhatsApp
                </a>
            </div>
        `;

        document.body.appendChild(container);

        // Remove automaticamente após 10 segundos
        setTimeout(function() {
            if (container.parentNode) {
                container.style.transition = 'opacity 0.3s, transform 0.3s';
                container.style.opacity = '0';
                container.style.transform = 'translateX(400px)';
                setTimeout(function() {
                    if (container.parentNode) {
                        container.parentNode.removeChild(container);
                    }
                }, 300);
            }
        }, 10000);
    }

    function showFallbackLink(url) {
        var statusEl = document.getElementById('hapvida-redirect-status');
        var fallbackEl = document.getElementById('hapvida-fallback-link');

        if (statusEl) {
            statusEl.textContent = 'Popup bloqueado pelo navegador';
            statusEl.style.color = '#ffeb3b';
        }

        if (fallbackEl) {
            fallbackEl.style.display = 'block';
        }

        // Se os elementos não existirem, cria um link simples
        if (!fallbackEl) {
            var link = document.createElement('a');
            link.href = url;
            link.target = '_blank';
            link.textContent = 'Clique aqui para abrir o WhatsApp';
            link.style.cssText = 'position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #25D366; color: white; padding: 20px 40px; border-radius: 12px; text-decoration: none; font-size: 18px; font-weight: 600; z-index: 9999; box-shadow: 0 4px 12px rgba(0,0,0,0.3);';
            document.body.appendChild(link);
        }
    }
})();