(function ($) {
    "use strict";

    var CFG = window.HapvidaSheetsConfig || {};
    var NONCE = CFG.nonce || "";
    var AJAX = CFG.ajaxUrl || "";
    var SHEETS = CFG.sheets || {};
    var HAS_CREDS = !!CFG.hasCreds;
    var SERVICE_EMAIL = CFG.serviceEmail || "";

    console.log("[Google Sheets] JS carregado. Creds:", HAS_CREDS, "Sheets:", Object.keys(SHEETS).length);

    function showToast(msg, type, duration) {
        var $t = $("#sheets-toast");
        $t.removeClass("success error info").addClass(type).text(msg).fadeIn(200);
        clearTimeout($t.data("timer"));
        $t.data("timer", setTimeout(function () { $t.fadeOut(300); }, duration || 4000));
    }

    function getVendorName($btn) {
        return $btn.closest(".vendedor-row").find('input[name*="[nome]"]').val() || "";
    }

    function setButtonLoading($btn, loading) {
        if (loading) {
            $btn.data("orig-text", $btn.html());
            $btn.prop("disabled", true).html('<span class="dashicons dashicons-update" style="animation:hapvida-spin 1s infinite linear"></span> ...');
        } else {
            $btn.prop("disabled", false).html($btn.data("orig-text"));
        }
    }

    function rebuildButtons($row, hasSheet) {
        $row.find(".sheets-btn-group").remove();
        $row.find(".vendedor-actions").append(buildButtonsHtml(hasSheet));
    }

    function buildButtonsHtml(hasSheet) {
        if (!hasSheet) {
            return '<div class="sheets-btn-group">' +
                '<button type="button" class="button button-small sheets-btn sheets-link-btn" title="Vincular Planilha">' +
                '<span class="dashicons dashicons-admin-links"></span> Vincular</button>' +
                '</div>';
        }
        return '<div class="sheets-btn-group">' +
            '<button type="button" class="button button-small sheets-btn sheets-update-btn" title="Atualizar Planilha">' +
            '<span class="dashicons dashicons-update"></span> Atualizar</button>' +
            '<button type="button" class="button button-small sheets-btn sheets-view-btn" title="Visualizar Planilha">' +
            '<span class="dashicons dashicons-visibility"></span> Ver</button>' +
            '<button type="button" class="button button-small sheets-btn sheets-change-btn" title="Trocar planilha">' +
            '<span class="dashicons dashicons-edit"></span></button>' +
            '</div>';
    }

    function linkSheet($btn, name, isReplace) {
        var msg = isReplace
            ? 'Cole o link da NOVA planilha para "' + name + '":'
            : 'Cole o link da planilha do Google Sheets para "' + name + '":\n\nCompartilhe com ' + SERVICE_EMAIL + ' (Editor)';
        var url = prompt(msg);
        if (!url || !url.trim()) return;

        setButtonLoading($btn, true);
        $.post(AJAX, {
            action: "hapvida_sheets_create",
            nonce: NONCE,
            vendor_name: name,
            spreadsheet_url: url.trim(),
            replace: isReplace ? 1 : 0
        })
            .done(function (res) {
                if (res.success) {
                    showToast(res.data.message, "success", 5000);
                    SHEETS[name] = { url: res.data.url };
                    rebuildButtons($btn.closest(".vendedor-row"), true);
                } else {
                    showToast("Erro: " + res.data, "error", 6000);
                }
            })
            .fail(function () { showToast("Erro de conexao. Tente novamente.", "error"); })
            .always(function () { setButtonLoading($btn, false); });
    }

    // === Event: Vincular Planilha (nova) ===
    $(document).on("click", ".sheets-link-btn", function () {
        var $btn = $(this);
        var name = getVendorName($btn);
        if (!name) { showToast("Preencha o nome do vendedor antes.", "error"); return; }
        if (!HAS_CREDS) { showToast("Configure as credenciais do Google primeiro.", "error"); return; }
        linkSheet($btn, name, false);
    });

    // === Event: Trocar Planilha (ja vinculada) ===
    $(document).on("click", ".sheets-change-btn", function () {
        var $btn = $(this);
        var name = getVendorName($btn);
        if (!name) return;

        var choice = prompt('Planilha de "' + name + '"\n\n1 = Trocar planilha (colar novo link)\n2 = Desvincular planilha\n\nDigite 1 ou 2:');
        if (!choice) return;

        if (choice.trim() === "2") {
            if (!confirm('Desvincular planilha de "' + name + '"?')) return;
            $.post(AJAX, { action: "hapvida_sheets_unlink", nonce: NONCE, vendor_name: name })
                .done(function (res) {
                    if (res.success) {
                        showToast(res.data.message, "success");
                        delete SHEETS[name];
                        rebuildButtons($btn.closest(".vendedor-row"), false);
                    } else {
                        showToast("Erro: " + res.data, "error");
                    }
                })
                .fail(function () { showToast("Erro de conexao.", "error"); });
            return;
        }

        if (choice.trim() === "1") {
            linkSheet($btn, name, true);
        }
    });

    // === Event: Atualizar Planilha ===
    $(document).on("click", ".sheets-update-btn:not(:disabled)", function () {
        var $btn = $(this);
        var name = getVendorName($btn);
        console.log("[Sheets] Atualizar clicado. Vendedor:", name, "| AJAX URL:", AJAX, "| Nonce:", NONCE ? "OK" : "VAZIO");
        if (!name) { showToast("Preencha o nome do vendedor.", "error"); return; }
        if (!HAS_CREDS) { showToast("Configure as credenciais do Google primeiro.", "error"); return; }
        if (!confirm('Duplicar ultima aba da planilha de "' + name + '"?\nIsso cria uma copia da ultima aba renomeada para o proximo mes.')) return;

        setButtonLoading($btn, true);
        console.log("[Sheets] Enviando AJAX hapvida_sheets_update para:", AJAX);
        $.post(AJAX, { action: "hapvida_sheets_update", nonce: NONCE, vendor_name: name })
            .done(function (res) {
                console.log("[Sheets] Resposta recebida:", JSON.stringify(res));
                if (res.success) { showToast(res.data.message, "success", 5000); }
                else { showToast("Erro: " + (typeof res.data === "string" ? res.data : JSON.stringify(res.data)), "error", 8000); }
            })
            .fail(function (xhr, status, err) {
                console.error("[Sheets] Falha AJAX:", status, err);
                console.error("[Sheets] Resposta do servidor:", xhr.responseText ? xhr.responseText.substring(0, 500) : "(vazia)");
                showToast("Erro de conexao. Verifique o console (F12) para detalhes.", "error", 8000);
            })
            .always(function () { setButtonLoading($btn, false); });
    });

    // === Event: Atualizar TODAS as Planilhas ===
    var isUpdatingAll = false;
    $(document).on("click", "#hapvida-sheets-update-all-btn:not(:disabled)", function () {
        if (isUpdatingAll) {
            console.log("[Sheets] Atualização já em andamento, ignorando clique duplicado.");
            return;
        }
        if (!HAS_CREDS) { showToast("Configure as credenciais do Google primeiro.", "error"); return; }

        var $btn = $(this);
        $btn.prop("disabled", true); // Desabilita ANTES do confirm para evitar double-click

        if (!confirm("Atualizar TODAS as planilhas vinculadas?\nIsso criara uma nova aba em cada planilha.")) {
            $btn.prop("disabled", false);
            return;
        }

        isUpdatingAll = true;
        $btn.find(".dashicons").addClass("spin");
        $btn.contents().last()[0].textContent = " Atualizando...";
        console.log("[Sheets] Atualizar TODAS clicado.");

        $.post(AJAX, { action: "hapvida_sheets_update_all", nonce: NONCE })
            .done(function (res) {
                console.log("[Sheets UpdateAll] Resposta:", JSON.stringify(res));
                if (res.success) {
                    showToast(res.data.message, "success", 8000);
                    // Log detalhes de cada vendedor no console
                    if (res.data.results) {
                        res.data.results.forEach(function (r) {
                            var icon = r.success ? "✅" : "❌";
                            console.log("[Sheets UpdateAll] " + icon + " " + r.vendor + ": " + r.message);
                        });
                    }
                } else {
                    showToast("Erro: " + (typeof res.data === "string" ? res.data : JSON.stringify(res.data)), "error", 8000);
                }
            })
            .fail(function (xhr, status, err) {
                console.error("[Sheets UpdateAll] Falha AJAX:", status, err);
                showToast("Erro de conexao ao atualizar planilhas.", "error", 8000);
            })
            .always(function () {
                isUpdatingAll = false;
                $btn.prop("disabled", false).find(".dashicons").removeClass("spin");
                $btn.contents().last()[0].textContent = " Atualizar Todas as Planilhas";
            });
    });

    // === Event: Visualizar Planilha ===
    $(document).on("click", ".sheets-view-btn:not(:disabled)", function () {
        var $btn = $(this);
        var name = getVendorName($btn);
        if (!name) { showToast("Preencha o nome do vendedor.", "error"); return; }

        if (SHEETS[name] && SHEETS[name].url) {
            window.open(SHEETS[name].url, "_blank");
            return;
        }

        setButtonLoading($btn, true);
        $.post(AJAX, { action: "hapvida_sheets_view", nonce: NONCE, vendor_name: name })
            .done(function (res) {
                if (res.success) { window.open(res.data.url, "_blank"); }
                else { showToast("Erro: " + res.data, "error"); }
            })
            .fail(function () { showToast("Erro de conexao.", "error"); })
            .always(function () { setButtonLoading($btn, false); });
    });

    // === Modal: Configurar credenciais ===
    $(document).on("click", "#hapvida-sheets-config-btn", function () {
        $("#sheets-modal-overlay").addClass("active");
    });
    $(document).on("click", "#sheets-modal-close", function () {
        $("#sheets-modal-overlay").removeClass("active");
    });
    $(document).on("click", "#sheets-modal-overlay, #sheets-test-result-overlay", function (e) {
        if (e.target === this) $(this).removeClass("active");
    });
    $(document).on("click", "#sheets-modal-save", function () {
        var $btn = $(this);
        var creds = $("#sheets-credentials-input").val().trim();
        if (!creds) { showToast("Cole o JSON da conta de servico.", "error"); return; }
        try { JSON.parse(creds); } catch (e) {
            showToast("JSON invalido. Verifique o conteudo.", "error"); return;
        }
        $btn.prop("disabled", true).text("Salvando...");
        $.post(AJAX, { action: "hapvida_sheets_save_credentials", nonce: NONCE, credentials: creds })
            .done(function (res) {
                if (res.success) {
                    showToast(res.data.message, "success");
                    HAS_CREDS = true;
                    SERVICE_EMAIL = res.data.email;
                    $("#hapvida-sheets-config-area .sheets-status")
                        .removeClass("sheets-status-warn").addClass("sheets-status-ok")
                        .text("Configurado (" + res.data.email + ")");
                    $("#sheets-modal-overlay").removeClass("active");
                } else {
                    showToast("Erro: " + res.data, "error", 6000);
                }
            })
            .fail(function () { showToast("Erro de conexao.", "error"); })
            .always(function () { $btn.prop("disabled", false).text("Salvar Credenciais"); });
    });

    // === Testar Conexao ===
    $(document).on("click", "#hapvida-sheets-test-btn", function () {
        var $btn = $(this);
        $btn.prop("disabled", true).html('<span class="dashicons dashicons-update" style="animation:hapvida-spin 1s infinite linear"></span> Testando...');
        $.post(AJAX, { action: "hapvida_sheets_test", nonce: NONCE })
            .done(function (res) {
                var txt = (res.success ? "SUCESSO!\n\n" : "ERRO!\n\n") + res.data;
                $("#sheets-test-result-content").val(txt);
                $("#sheets-test-result-overlay").addClass("active");
                if (res.success) {
                    showToast("Conexao OK!", "success");
                } else {
                    showToast("Falha no teste.", "error");
                }
            })
            .fail(function () {
                $("#sheets-test-result-content").val("Erro de conexao com o servidor.");
                $("#sheets-test-result-overlay").addClass("active");
                showToast("Erro de conexao.", "error");
            })
            .always(function () { $btn.prop("disabled", false).html('<span class="dashicons dashicons-yes-alt"></span> Testar Conexao'); });
    });

})(jQuery);