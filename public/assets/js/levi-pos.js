/* =========================================================================
 * LEVI POS - Front-end Controller
 * Offline-first, RTL, permission-aware SPA controller.
 * PHP 8.0 backend / Vanilla JS (no CDN).
 * ========================================================================= */
(function () {
    'use strict';

    const LeviPOS = {
        state: {
            boot: window.LEVI_BOOT || {},
            section: 'dashboard',
            settings: null,
            settingsLoaded: false,
            categories: [],
            brands: [],
            rates: [],
            products: [],
            allProducts: [],
            suppliers: [],
            customers: [],
            customerMap: {},
            cart: [],
            customer: null,
            customerResults: [],
            catalog: [],
            session: null,
            registers: [],
            heldSaleId: null,
            clientUuid: null,
            lastTotal: 0,
            isSubmitting: false,
            charts: {},
            expenseCategories: [],
            roles: [],
            branches: [],
            users: [],
            reportTypes: null,
            posMetaLoaded: false,
            showArchivedProducts: false,
            timers: {},
            searchReturnResults: [],
            returnInvoice: null,
            ownerCleanupFilters: null,
            ownerCleanupGroups: null,
            ownerCleanupLabels: null,
            batches: [],
            expiryAlerts: [],
            doctors: [],
            prescriptions: [],
            openPrescriptions: [],
            commandItems: [],
            commandIndex: 0,
            commandToken: 0
        },

        /* =================================================================
         * Helpers
         * ================================================================= */
        boot() {
            return this.state.boot || {};
        },

        perm(key) {
            const perms = this.boot().permissions || {};
            return !!perms[key];
        },

        has(code) {
            const map = {
                'products.manage': 'products_manage',
                'products.view_cost': 'products_cost',
                'inventory.adjust': 'inventory_adjust',
                'pos.cancel_invoice': 'cancel_invoice',
                'cash_register.close': 'cash_close',
                'returns.create': 'returns',
                'settings.manage': 'settings',
                'users.manage': 'users',
                'backups.manage': 'backups',
                'expenses.manage': 'expenses',
                'purchases.manage': 'purchases',
                'suppliers.manage': 'suppliers',
                'customers.manage': 'customers',
                'batches.manage': 'batches_manage',
                'expiry.view': 'expiry',
                'doctors.manage': 'doctors_manage',
                'prescriptions.manage': 'prescriptions_manage',
                'patients.manage': 'customers',
                'reports.view_financial': 'reports_view_financial',
                'reports.export': 'reports_export',
                'cash_register.manage': 'cash_register_manage',
                'pos.resume_held': 'pos_resume_held',
                'audit.view': 'audit',
                'dashboard.view': 'dashboard',
                'pos.use': 'pos',
                'products.view': 'products',
                'inventory.view': 'inventory',
                'invoices.view': 'invoices'
            };
            return this.perm(map[code] || code);
        },

        escapeHtml(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        mediaUrl(path) {
            const value = String(path == null ? '' : path).trim();
            if (!value) return '';
            if (/^(https?:)?\/\//i.test(value) || value.indexOf('data:') === 0) return value;
            return value.replace(/^\/+/, '');
        },

        num(value) {
            const n = Number(value);
            return isFinite(n) ? n : 0;
        },

        money(value) {
            return this.num(value).toFixed(2) + ' ' + (this.boot().baseCurrency || 'LYD');
        },

        qty(value) {
            const n = this.num(value);
            return Number.isInteger(n) ? String(n) : n.toFixed(2);
        },

        arabicCount(count, forms) {
            const n = this.num(count);
            if (n === 1) return forms.one;
            if (n === 2) return forms.two;
            if (n >= 3 && n <= 10) return n + ' ' + forms.few;
            return n + ' ' + forms.many;
        },

        dateOnly(value) {
            if (!value) return '';
            return String(value).substring(0, 10);
        },

        formatDateParts(parts) {
            const fmt = this.boot().dateFormat || 'Y-m-d H:i';
            let out = '';
            for (let i = 0; i < fmt.length; i++) {
                const ch = fmt.charAt(i);
                if (ch === '\\') {
                    out += fmt.charAt(++i);
                } else if (parts[ch] !== undefined) {
                    out += parts[ch];
                } else {
                    out += ch;
                }
            }
            return out;
        },

        dateTime(value) {
            if (!value) return '';
            const text = String(value).replace('T', ' ');
            const m = text.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ ](\d{2}):(\d{2})(?::(\d{2}))?)?/);
            if (!m) return text.substring(0, 16);
            return this.formatDateParts({
                Y: m[1], y: m[1].substring(2), m: m[2], n: String(Number(m[2])), d: m[3], j: String(Number(m[3])),
                H: m[4] || '00', G: String(Number(m[4] || 0)), i: m[5] || '00', s: m[6] || '00'
            });
        },

        statusLabel(status) {
            const map = {
                completed: 'مكتملة', held: 'معلقة', cancelled: 'ملغاة', returned: 'مرتجعة',
                sale: 'بيع', payment: 'دفعة/سداد', return: 'مرتجع',
                paid: 'مدفوعة', partial: 'مدفوعة جزئيًا', unpaid: 'غير مدفوعة',
                open: 'مفتوحة', closed: 'مغلقة',
                active: 'سارية', expired: 'منتهية', under_maintenance: 'تحت الصيانة',
                replaced: 'مستبدلة', void: 'ملغاة',
                cash: 'نقدي', card: 'بطاقة', transfer: 'تحويل', cheque: 'شيك', bank_transfer: 'تحويل مصرفي',
                split: 'مختلط', credit: 'آجل', debt_deduction: 'خصم من الدين',
                resellable: 'قابل لإعادة البيع', damaged: 'تالف', needs_inspection: 'يحتاج فحصًا',
                non_resellable: 'غير قابل لإعادة البيع',
                product_discount: 'خصم منتج', category_discount: 'خصم تصنيف',
                invoice_discount: 'خصم فاتورة', bundle: 'حزمة',
                percentage: 'نسبة مئوية', fixed: 'مبلغ ثابت',
                manual_in: 'إيداع يدوي', manual_out: 'سحب يدوي',
                sale_in: 'مبيعات نقدية', refund_out: 'مرتجعات نقدية',
                expense_out: 'مصروفات نقدية', debt_collection: 'تحصيل ديون',
                purchase_out: 'مشتريات نقدية', supplier_payment_out: 'دفعات موردين',
                cancel_out: 'إلغاء فواتير', purchase_cancel_in: 'إلغاء مشتريات',
                purchase_return_in: 'مرتجع مشتريات',
                purchase: 'شراء', sale_return: 'مرتجع بيع', purchase_return: 'مرتجع شراء',
                adjustment: 'تسوية مخزون', stocktake: 'جرد', lost: 'مفقود', initial: 'رصيد افتتاحي',
                sale_cancel: 'إلغاء بيع', purchase_cancel: 'إلغاء شراء',
                transfer_out: 'تحويل صادر', transfer_in: 'تحويل وارد',
                manual: 'يدوية', auto: 'تلقائية', pre_restore: 'قبل الاستعادة',
                local: 'محلي', usb: 'ذاكرة USB', external_disk: 'قرص خارجي'
            };
            return map[status] || status || '';
        },

        statementTypeLabel(type) {
            const map = {
                sale: 'فاتورة بيع', purchase: 'فاتورة شراء',
                payment: 'دفعة/سداد', return: 'مرتجع'
            };
            return map[type] || this.statusLabel(type);
        },

        actionLabel(action) {
            const map = {
                BACKUP_CREATED: 'إنشاء نسخة احتياطية', BACKUP_DELETED: 'حذف نسخة احتياطية',
                BACKUP_RESTORED: 'استعادة نسخة احتياطية',
                BATCH_CREATED: 'إضافة دفعة دواء', BATCH_DELETED: 'حذف دفعة دواء',
                BATCH_UPDATED: 'تعديل دفعة دواء',
                BRANCH_CREATED: 'إضافة فرع', BRANCH_UPDATED: 'تعديل فرع',
                BRAND_CREATED: 'إضافة شركة دواء', BRAND_UPDATED: 'تعديل شركة دواء',
                CASH_MANUAL_MOVEMENT: 'حركة نقدية يدوية',
                CASH_SESSION_CLOSED: 'إغلاق وردية الصندوق', CASH_SESSION_OPENED: 'فتح وردية الصندوق',
                CATEGORY_CREATED: 'إضافة تصنيف', CATEGORY_UPDATED: 'تعديل تصنيف',
                CUSTOMER_ARCHIVED: 'أرشفة مريض/عميل', CUSTOMER_CREATED: 'إضافة مريض/عميل',
                CUSTOMER_DELETED: 'حذف مريض/عميل', CUSTOMER_PAYMENT: 'تسديد دفعة من مريض/عميل',
                CUSTOMER_UPDATED: 'تعديل ملف مريض/عميل',
                DOCTOR_CREATED: 'إضافة طبيب', DOCTOR_DELETED: 'حذف طبيب',
                DOCTOR_UPDATED: 'تعديل بيانات طبيب',
                EXPENSE_CANCELLED: 'إلغاء مصروف', EXPENSE_CATEGORY_CREATED: 'إضافة تصنيف مصروف',
                EXPENSE_CATEGORY_UPDATED: 'تعديل تصنيف مصروف', EXPENSE_CREATED: 'إضافة مصروف',
                EXPENSE_UPDATED: 'تعديل مصروف',
                HELD_SALE_DELETED: 'حذف فاتورة معلقة',
                LOGIN_BLOCKED: 'محاولة دخول محظورة', LOGIN_FAILED: 'فشل تسجيل الدخول',
                LOGIN_SUCCESS: 'تسجيل دخول ناجح', LOGOUT: 'تسجيل خروج',
                PASSWORD_CHANGED: 'تغيير كلمة المرور', PASSWORD_RESET: 'إعادة تعيين كلمة المرور',
                PRODUCT_ARCHIVED: 'أرشفة منتج', PRODUCT_CREATED: 'إضافة منتج',
                PRODUCT_RESTORED: 'استعادة منتج', PRODUCT_SOFT_DELETED: 'حذف منتج',
                PRODUCT_UPDATED: 'تعديل منتج', PRODUCTS_IMPORTED: 'استيراد منتجات',
                PRESCRIPTION_CREATED: 'إضافة روشتة', PRESCRIPTION_DELETED: 'حذف روشتة',
                PRESCRIPTION_DISPENSED: 'صرف روشتة', PRESCRIPTION_UPDATED: 'تعديل روشتة',
                PURCHASE_CANCELLED: 'إلغاء فاتورة شراء', PURCHASE_CREATED: 'إضافة فاتورة شراء',
                PURCHASE_RETURNED: 'مرتجع مشتريات',
                REGISTER_CREATED: 'إضافة صندوق', REGISTER_UPDATED: 'تعديل صندوق',
                ROLE_PERMISSIONS_UPDATED: 'تحديث صلاحيات الدور',
                SALE_CANCELLED: 'إلغاء فاتورة مبيعات', SALE_COMPLETED: 'إتمام فاتورة مبيعات',
                SALE_HELD: 'تعليق فاتورة مبيعات', SALE_RESUMED: 'استئناف فاتورة معلقة',
                SALE_RETURNED: 'مرتجع مبيعات',
                SETTINGS_UPDATED: 'تحديث الإعدادات', STOCK_ADJUSTMENT: 'تسوية مخزون',
                SUPPLIER_ARCHIVED: 'أرشفة مورد', SUPPLIER_CREATED: 'إضافة مورد',
                SUPPLIER_DELETED: 'حذف مورد', SUPPLIER_PAYMENT: 'تسديد دفعة لمورد',
                SUPPLIER_UPDATED: 'تعديل مورد',
                SYSTEM_INIT: 'تهيئة النظام', UNAUTHORIZED_ACCESS: 'محاولة وصول غير مصرح',
                USER_CREATED: 'إضافة مستخدم', USER_DELETED: 'حذف مستخدم',
                USER_UNLOCKED: 'فتح قفل مستخدم', USER_UPDATED: 'تعديل مستخدم'
            };
            return map[action] || action || '';
        },

        badge(text, tone) {
            const cls = tone === 'green' ? 'pill-green'
                : tone === 'red' ? 'pill-red'
                : tone === 'amber' ? 'pill-amber'
                : tone === 'blue' ? 'pill-blue'
                : 'pill-gray';
            return '<span class="pill ' + cls + '">' + this.escapeHtml(text) + '</span>';
        },

        statusTone(status) {
            return (status === 'completed' || status === 'paid' || status === 'active' || status === 'open')
                ? 'green'
                : (status === 'cancelled' || status === 'expired' || status === 'void')
                    ? 'red'
                    : (status === 'held' || status === 'partial' || status === 'under_maintenance')
                        ? 'amber'
                        : 'blue';
        },

        statusBadge(status) {
            return this.badge(this.statusLabel(status), this.statusTone(status));
        },

        debounce(key, fn, delay) {
            const self = this;
            clearTimeout(this.state.timers[key]);
            this.state.timers[key] = setTimeout(function () { fn.call(self); }, delay || 250);
        },

        generateUuid() {
            if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                return window.crypto.randomUUID();
            }
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
                const r = Math.random() * 16 | 0;
                const v = c === 'x' ? r : (r & 0x3 | 0x8);
                return v.toString(16);
            });
        },

        ensureClientUuid() {
            if (!this.state.clientUuid) {
                this.state.clientUuid = this.generateUuid();
            }
            return this.state.clientUuid;
        },

        options(items, valueKey, labelKey, selected, placeholder) {
            let html = '';
            if (placeholder) {
                html += '<option value="">' + this.escapeHtml(placeholder) + '</option>';
            }
            (items || []).forEach(function (item) {
                const value = typeof valueKey === 'function' ? valueKey(item) : item[valueKey];
                const label = typeof labelKey === 'function' ? labelKey(item) : item[labelKey];
                const sel = String(value) === String(selected == null ? '' : selected) ? ' selected' : '';
                html += '<option value="' + this.escapeHtml(String(value == null ? '' : value)) + '"' + sel + '>'
                    + this.escapeHtml(String(label == null ? '' : label)) + '</option>';
            }, this);
            return html;
        },

        fillSelect(id, items, valueKey, labelKey, selected, placeholder) {
            const el = document.getElementById(id);
            if (!el) return;
            el.innerHTML = this.options(items, valueKey, labelKey, selected, placeholder);
        },

        renderTable(target, columns, rows, emptyText) {
            const el = typeof target === 'string' ? document.getElementById(target) : target;
            if (!el) return;
            if (!rows || !rows.length) {
                el.innerHTML = '<div class="empty-state">' + this.escapeHtml(emptyText || 'لا توجد بيانات لعرضها.') + '</div>';
                return;
            }
            let head = '<tr>';
            columns.forEach(function (c) { head += '<th>' + (c.labelHtml ? c.labelHtml : this.escapeHtml(c.label)) + '</th>'; }, this);
            head += '</tr>';
            let body = '';
            rows.forEach(function (row, index) {
                body += '<tr>';
                columns.forEach(function (c) {
                    let value;
                    if (typeof c.render === 'function') {
                        value = c.render.call(this, row, index);
                    } else {
                        value = this.escapeHtml(row[c.key] == null ? '' : row[c.key]);
                    }
                    body += '<td>' + value + '</td>';
                }, this);
                body += '</tr>';
            }, this);
            el.innerHTML = '<div class="table-responsive"><table class="table table-sm"><thead>' + head
                + '</thead><tbody>' + body + '</tbody></table></div>';
        },

        statCard(label, value, tone, icon) {
            if (icon) {
                const toneKey = tone === 'green' || tone === 'red' || tone === 'amber'
                    || tone === 'blue' || tone === 'gray' || tone === 'violet' ? tone : 'blue';
                let display = value;
                const raw = String(value);
                const currency = this.boot().baseCurrency || 'LYD';
                if (raw.indexOf('<') === -1 && raw.slice(-(currency.length + 1)) === ' ' + currency) {
                    display = this.escapeHtml(raw.slice(0, -(currency.length + 1)).trim())
                        + ' <small>' + this.escapeHtml(currency) + '</small>';
                }
                return '<div class="pgx-kpi is-' + toneKey + '">'
                    + '<span class="pgx-kpi-ic" aria-hidden="true">' + icon + '</span>'
                    + '<span class="pgx-kpi-body">'
                    + '<span class="pgx-kpi-label">' + this.escapeHtml(label) + '</span>'
                    + '<span class="pgx-kpi-value">' + display + '</span>'
                    + '</span></div>';
            }
            const cls = tone === 'green' ? 'var(--levi-success)'
                : tone === 'red' ? 'var(--levi-danger)'
                : tone === 'amber' ? 'var(--levi-warning)'
                : 'var(--levi-primary)';
            return '<div class="card stat-card" style="border-right-color:' + cls + '">'
                + '<span class="stat-label">' + this.escapeHtml(label) + '</span>'
                + '<span class="stat-value">' + value + '</span></div>';
        },

        kpiIcon(name) {
            const icons = {
                sales: '<path d="m3 17 6-6 4 4 8-8"/><path d="M21 7v5h-5"/>',
                invoices: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8Z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h6"/>',
                collected: '<path d="M3 7h16a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/><path d="M3 7V6a2 2 0 0 1 2-2h11"/><circle cx="17" cy="13" r="1.3"/>',
                debts: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
                'month-sales': '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="m8 15 2.5 2.5L16 12"/>',
                profit: '<path d="m3 17 6-6 4 4 8-8"/><path d="M21 7v5h-5"/>',
                expenses: '<path d="M3 7h16a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/><path d="m9 13 6 4M15 13l-6 4"/>',
                'net-profit': '<path d="M21.2 15.9A10 10 0 1 1 8.1 2.8"/><path d="M22 12A10 10 0 0 0 12 2v10Z"/>',
                'inventory-value': '<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5"/><path d="m3 16.5 9 5 9-5"/>',
                products: '<path d="M21 8 12 3 3 8v8l9 5 9-5Z"/><path d="M3 8l9 5 9-5"/><path d="M12 13v8"/>',
                'low-stock': '<path d="M12 3 2.8 19a1.5 1.5 0 0 0 1.3 2.2h15.8A1.5 1.5 0 0 0 21.2 19Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
                'out-of-stock': '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/>'
            };
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
                + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                + (icons[name] || icons.sales) + '</svg>';
        },

        openModal(title, body, footer, size) {
            document.getElementById('modal-title').textContent = title || '';
            document.getElementById('modal-body').innerHTML = body || '';
            document.getElementById('modal-footer').innerHTML = footer || '';
            const box = document.getElementById('modal-box');
            box.className = 'modal-box' + (size === 'wide' ? ' modal-wide' : size === 'narrow' ? ' modal-narrow' : '');
            document.getElementById('modal-overlay').classList.add('open');
        },

        closeModal() {
            if (this.forcePasswordChange) {
                this.toast('يجب تغيير كلمة المرور الافتراضية قبل استخدام النظام.', 'warning');
                return;
            }
            const overlay = document.getElementById('modal-overlay');
            if (!overlay) return;
            overlay.classList.remove('open');
            document.getElementById('modal-body').innerHTML = '';
            document.getElementById('modal-footer').innerHTML = '';
            document.getElementById('modal-title').textContent = '';
        },

        toast(message, type) {
            const container = document.getElementById('toast-container');
            if (!container) return;
            const colors = { success: '#49836b', error: '#ac5a52', warning: '#a87b2c', info: '#4c7396' };
            const el = document.createElement('div');
            el.className = 'toast-msg';
            el.style.background = colors[type] || colors.info;
            el.textContent = message;
            container.appendChild(el);
            setTimeout(function () { el.remove(); }, 4200);
        },

        async handleResponse(response) {
            let json = null;
            try { json = await response.json(); } catch (e) { json = null; }
            if (response.status === 401) {
                window.location.href = 'login.php';
                return null;
            }
            if (!json) {
                this.toast('استجابة غير صالحة من الخادم.', 'error');
                return null;
            }
            if (!response.ok || json.status === 'error') {
                this.toast(json.message || 'حدث خطأ غير متوقع.', 'error');
                return null;
            }
            return json.data !== undefined ? json.data : json;
        },

        async apiGet(action, params) {
            params = params || {};
            const qs = new URLSearchParams();
            qs.set('action', action);
            Object.keys(params).forEach(function (key) {
                const value = params[key];
                if (value !== undefined && value !== null && value !== '') {
                    qs.set(key, value);
                }
            }, this);
            try {
                const response = await fetch('api.php?' + qs.toString(), {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                });
                return await this.handleResponse(response);
            } catch (e) {
                this.toast('تعذر الاتصال بالخادم: ' + e.message, 'error');
                return null;
            }
        },

        async apiPost(action, body) {
            body = body || {};
            const csrf = this.boot().csrf || '';
            const payload = Object.assign({}, body, { _csrf_token: csrf });
            try {
                const response = await fetch('api.php?action=' + encodeURIComponent(action), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-Token': csrf
                    },
                    body: JSON.stringify(payload)
                });
                return await this.handleResponse(response);
            } catch (e) {
                this.toast('تعذر الاتصال بالخادم: ' + e.message, 'error');
                return null;
            }
        },

        async apiUpload(action, formData) {
            const csrf = this.boot().csrf || '';
            try { formData.append('_csrf_token', csrf); } catch (e) { /* ignore */ }
            try {
                const response = await fetch('api.php?action=' + encodeURIComponent(action), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-Token': csrf
                    },
                    body: formData
                });
                return await this.handleResponse(response);
            } catch (e) {
                this.toast('تعذر الاتصال بالخادم: ' + e.message, 'error');
                return null;
            }
        },

        downloadText(filename, text, mime) {
            const blob = new Blob([text], { type: mime || 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            setTimeout(function () { URL.revokeObjectURL(link.href); }, 1000);
        },

        /**
         * Sends the ESC/POS drawer pulse after a cash sale or cash refund.
         * Supports a locally installed bridge (window.leviOpenCashDrawer), a
         * configurable local agent endpoint, and a DOM event fallback. The
         * drawer is a best-effort device action and must never block the sale.
         */
        openCashDrawer() {
            const pulse = '1B700019FA';
            try {
                if (typeof window.leviOpenCashDrawer === 'function') {
                    window.leviOpenCashDrawer({ command: 'open_drawer', pulse: pulse });
                    return true;
                }
                let endpoint = window.LEVI_DRAWER_ENDPOINT || '';
                try { endpoint = endpoint || localStorage.getItem('levi_drawer_endpoint') || ''; } catch (ignore) {}
                if (endpoint) {
                    fetch(endpoint, {
                        method: 'POST',
                        mode: 'no-cors',
                        headers: { 'Content-Type': 'text/plain' },
                        body: pulse
                    }).catch(function () {});
                    return true;
                }
                document.dispatchEvent(new CustomEvent('levi:open-cash-drawer', {
                    detail: { command: 'open_drawer', pulse: pulse }
                }));
            } catch (e) {
                // Ignore device errors; never interrupt checkout.
            }
            return false;
        },

        productKey(product) {
            return String(product.product_id) + '|' + String(product.variant_id || '');
        },

        findProductByKey(key) {
            return (this.state.allProducts || []).find(function (p) { return this.productKey(p) === String(key); }, this) || null;
        },

        productLabel(product) {
            let label = product.product_name || '';
            if (product.variant_name) label += ' - ' + product.variant_name;
            if (product.sku) label += ' [' + product.sku + ']';
            return label;
        },

        productOptionHtml(products, selected) {
            return this.options(products, function (p) { return this.productKey(p); }.bind(this),
                function (p) { return this.productLabel(p); }.bind(this), selected, 'اختر المنتج...');
        },

        /* =================================================================
         * Init / navigation
         * ================================================================= */
        init() {
            this.initTheme();
            this.applyPermissions();
            this.initSidebar();
            this.initNavDrawer();
            this.bindNavigation();
            this.bindTabs();
            this.bindPosEvents();
            this.bindPosBarcodeScanner();
            this.bindLiveSearch();
            this.bindGlobalEvents();
            this.bindTopbarUserMenu();
            this.startClock();
            this.refreshSessionBadge();
            this.navigate(this.initialView());
            this.loadReferenceData();
            const overlay = document.getElementById('modal-overlay');
            if (overlay) {
                overlay.addEventListener('click', function (event) {
                    if (event.target === overlay) this.closeModal();
                }.bind(this));
            }
            const boot = this.boot();
            if (boot && boot.user && Number(boot.user.must_change_password) === 1) {
                this.openChangePassword(true);
            }
        },

        applyPermissions() {
            const self = this;
            document.querySelectorAll('[data-perm]').forEach(function (el) {
                if (!self.perm(el.getAttribute('data-perm'))) {
                    const li = el.closest('li');
                    (li || el).style.display = 'none';
                    if (li) li.dataset.permHidden = '1';
                }
            });
            document.querySelectorAll('[data-perm-action]').forEach(function (el) {
                if (!self.has(el.getAttribute('data-perm-action'))) {
                    el.style.display = 'none';
                }
            });
            this.refreshSidebarGroups();
        },

        initSidebar() {
            const self = this;
            const toggle = document.getElementById('sidebar-toggle');

            /* القائمة الجانبية تعمل دائمًا كشريط أيقونات — لا حالة طيّ قابلة للتخزين */
            document.body.classList.remove('sidebar-collapsed');
            this.syncSidebarToggle();

            if (toggle) {
                toggle.addEventListener('click', function () {
                    document.body.classList.toggle('sidebar-collapsed');
                    const collapsed = document.body.classList.contains('sidebar-collapsed');
                    try {
                        localStorage.setItem('levi.sidebarCollapsed', collapsed ? '1' : '0');
                    } catch (e) { /* private mode */ }
                    self.syncSidebarToggle();
                    self.hideSidebarTip();
                });
            }

            const card = document.createElement('div');
            card.className = 'appsb-hover-card';
            card.setAttribute('role', 'tooltip');
            card.innerHTML = '<b></b>';
            document.body.appendChild(card);
            this._sidebarTip = card;

            document.querySelectorAll('.sidebar-link').forEach(function (link) {
                const show = function () {
                    const r = link.getBoundingClientRect();
                    card.querySelector('b').textContent = link.getAttribute('data-tip') || link.textContent.trim();
                    card.classList.add('is-on');
                    const cw = card.offsetWidth || 120;
                    const ch = card.offsetHeight || 40;
                    let left = r.left - cw - 12;
                    if (left < 8) left = r.right + 12;
                    const top = Math.max(8, Math.min(r.top + r.height / 2 - ch / 2, window.innerHeight - ch - 8));
                    card.style.left = left + 'px';
                    card.style.top = top + 'px';
                };
                link.addEventListener('mouseenter', show);
                link.addEventListener('focus', show);
                link.addEventListener('mouseleave', self.hideSidebarTip.bind(self));
                link.addEventListener('blur', self.hideSidebarTip.bind(self));
                link.addEventListener('click', self.hideSidebarTip.bind(self));
            });

            const scrollZone = document.getElementById('sidebar-scroll');
            if (scrollZone) scrollZone.addEventListener('scroll', function () { self.hideSidebarTip(); }, { passive: true });
            window.addEventListener('resize', function () { self.hideSidebarTip(); });

            const topbar = document.querySelector('.topbar-care');
            const brand = document.querySelector('.sidebar-brand');
            const syncScrollFade = function () {
                const scrolled = window.scrollY > 8;
                if (topbar) topbar.classList.toggle('is-scrolled', scrolled);
                if (brand) brand.classList.toggle('is-scrolled', scrolled);
            };
            if (topbar || brand) {
                window.addEventListener('scroll', syncScrollFade, { passive: true });
                document.addEventListener('scroll', syncScrollFade, { passive: true });
                syncScrollFade();
            }

            this.refreshSidebarGroups();
            if (this.perm('pos')) this.refreshHeldBadge();
        },

        initNavDrawer() {
            const self = this;
            const drawer = document.getElementById('nav-drawer');
            if (!drawer) return;
            const backdrop = document.getElementById('nav-drawer-backdrop');
            const openBtn = document.getElementById('nav-drawer-open');
            const closeBtn = document.getElementById('nav-drawer-close');
            const search = document.getElementById('nav-drawer-search');
            this._navDrawer = drawer;
            this._navDrawerBackdrop = backdrop;
            this._navDrawerOpen = false;

            if (openBtn) {
                openBtn.addEventListener('click', function () { self.setNavDrawerOpen(true); });
            }
            if (closeBtn) {
                closeBtn.addEventListener('click', function () { self.setNavDrawerOpen(false); });
            }
            if (backdrop) {
                backdrop.addEventListener('click', function () { self.setNavDrawerOpen(false); });
            }
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && self._navDrawerOpen) self.setNavDrawerOpen(false);
            });
            if (search) {
                search.addEventListener('input', function () { self.filterNavDrawer(search.value); });
            }
            drawer.querySelectorAll('.drawer-link').forEach(function (link) {
                link.addEventListener('click', function () { self.setNavDrawerOpen(false); });
            });
            this.filterNavDrawer('');
        },

        setNavDrawerOpen(open) {
            const drawer = this._navDrawer;
            if (!drawer) return;
            const backdrop = this._navDrawerBackdrop;
            const openBtn = document.getElementById('nav-drawer-open');
            const search = document.getElementById('nav-drawer-search');
            const next = !!open;
            this._navDrawerOpen = next;
            drawer.classList.toggle('is-open', next);
            drawer.setAttribute('aria-hidden', next ? 'false' : 'true');
            if (openBtn) openBtn.setAttribute('aria-expanded', next ? 'true' : 'false');
            if (this._navBackdropTimer) {
                clearTimeout(this._navBackdropTimer);
                this._navBackdropTimer = null;
            }
            if (backdrop) {
                if (next) {
                    backdrop.hidden = false;
                    requestAnimationFrame(function () { backdrop.classList.add('is-open'); });
                    window.setTimeout(function () { if (search) search.focus(); }, 80);
                } else {
                    backdrop.classList.remove('is-open');
                    this._navBackdropTimer = window.setTimeout(function () { backdrop.hidden = true; }, 240);
                }
            }
            if (!next && search) {
                search.value = '';
                this.filterNavDrawer('');
            }
        },

        filterNavDrawer(query) {
            const q = String(query || '').trim().toLowerCase();
            document.querySelectorAll('#nav-drawer .drawer-group').forEach(function (group) {
                let visible = 0;
                group.querySelectorAll('.drawer-link').forEach(function (link) {
                    const li = link.closest('li');
                    const permHidden = li && li.dataset.permHidden === '1';
                    const text = (link.getAttribute('data-tip') || link.textContent || '').toLowerCase();
                    const show = !permHidden && (!q || text.indexOf(q) !== -1);
                    if (li) li.style.display = show ? '' : 'none';
                    if (show) visible += 1;
                });
                group.style.display = visible ? '' : 'none';
            });
        },

        async refreshHeldBadge() {
            const data = await this.apiGet('pos.held_invoices');
            if (!data) return;
            this.setSidebarBadge('sidebar-badge-held', (data.held_invoices || []).length, 'amber');
        },

        hideSidebarTip() {
            if (this._sidebarTip) this._sidebarTip.classList.remove('is-on');
        },

        syncSidebarToggle() {
            const toggle = document.getElementById('sidebar-toggle');
            if (!toggle) return;
            const collapsed = document.body.classList.contains('sidebar-collapsed');
            const label = collapsed ? 'فتح القائمة' : 'طي القائمة';
            toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            toggle.setAttribute('aria-label', label);
            toggle.setAttribute('title', label);
        },

        refreshSidebarGroups() {
            document.querySelectorAll('.appsb-group').forEach(function (group) {
                const items = group.querySelectorAll('.appsb-list > li');
                if (!items.length) return;
                let visible = 0;
                items.forEach(function (li) {
                    if (li.style.display !== 'none') visible += 1;
                });
                group.style.display = visible ? '' : 'none';
            });
            document.querySelectorAll('.appsb-pin').forEach(function (pin) {
                const groups = pin.querySelectorAll('.appsb-group');
                let any = false;
                groups.forEach(function (g) {
                    if (g.style.display !== 'none') any = true;
                });
                pin.style.display = any ? '' : 'none';
            });
        },

        setSidebarBadge(id, count, tone) {
            const n = Number(count) || 0;
            const text = n > 0 ? (n > 99 ? '99+' : String(n)) : '';
            const apply = function (el) {
                if (!el) return;
                if (n <= 0) {
                    el.hidden = true;
                    el.textContent = '';
                    return;
                }
                el.hidden = false;
                el.textContent = text;
                el.classList.remove('is-amber', 'is-red', 'is-blue');
                if (tone) el.classList.add('is-' + tone);
            };
            apply(document.getElementById(id));
            document.querySelectorAll('[data-badge-for]').forEach(function (mirror) {
                if (mirror.getAttribute('data-badge-for') === id) apply(mirror);
            });
        },

        bindNavigation() {
            const self = this;
            document.querySelectorAll('[data-nav-target]').forEach(function (el) {
                el.addEventListener('click', function (event) {
                    event.preventDefault();
                    self.navigate(el.getAttribute('data-nav-target'));
                });
            });
        },

        bindTabs() {
            const self = this;
            document.querySelectorAll('[data-tab-group]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const group = btn.getAttribute('data-tab-group');
                    const tab = btn.getAttribute('data-tab');
                    document.querySelectorAll('[data-tab-group="' + group + '"]').forEach(function (b) {
                        b.classList.toggle('active', b === btn);
                    });
                    document.querySelectorAll('[id^="tab-' + group + '-"]').forEach(function (panel) {
                        panel.classList.toggle('active', panel.id === 'tab-' + group + '-' + tab);
                    });
                    self.onTab(group, tab);
                });
            });
        },

        navigate(target) {
            if (!target) return;
            const section = document.getElementById('view-' + target);
            if (!section) return;
            this.setNavDrawerOpen(false);
            document.querySelectorAll('.sidebar-link,.drawer-link').forEach(function (link) {
                link.classList.toggle('active', link.getAttribute('data-nav-target') === target);
            });
            document.querySelectorAll('.view-section').forEach(function (sec) {
                sec.classList.toggle('active', sec === section);
            });
            this.state.section = target;
            this.rememberView(target);
            this.loadSection(target);
        },

        initialView() {
            let stored = '';
            try { stored = localStorage.getItem('levi.activeView') || ''; } catch (e) { /* private mode */ }
            const hash = String(window.location.hash || '').replace(/^#/, '');
            const candidates = [hash, stored];
            for (let i = 0; i < candidates.length; i += 1) {
                const target = candidates[i];
                if (!target || !document.getElementById('view-' + target)) continue;
                const link = document.querySelector('[data-nav-target="' + target + '"]');
                if (!link) continue;
                const item = link.closest('li');
                if (item && item.style.display === 'none') continue;
                return target;
            }
            return 'dashboard';
        },

        rememberView(target) {
            try { window.history.replaceState(null, '', '#' + target); } catch (e) {
                try { window.location.hash = target; } catch (ignore) { /* ignore */ }
            }
            try { localStorage.setItem('levi.activeView', target); } catch (e) { /* private mode */ }
        },

        localToday() {
            const d = new Date();
            const m = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            return d.getFullYear() + '-' + m + '-' + day;
        },

        warmStatConfig() {
            const self = this;
            const sum = function (rows, key) {
                return rows.reduce(function (total, row) { return total + self.num(row[key]); }, 0);
            };
            const monthStart = this.localToday().substring(0, 8) + '01';
            return {
                batches: {
                    cards: [
                        { label: 'إجمالي الدفعات', emoji: '📦' },
                        { label: 'دفعات سارية', emoji: '✅', tone: 'green' },
                        { label: 'قريبة الانتهاء', emoji: '⏳', tone: 'amber' },
                        { label: 'منتهية', emoji: '🚫', tone: 'red' }
                    ],
                    load: async function () {
                        const d = await this.apiGet('batches.list', { limit: 1000 });
                        if (!d) return null;
                        const rows = d.batches || [];
                        const expired = rows.filter(function (r) { return r.expiry_status === 'expired'; }).length;
                        const near = rows.filter(function (r) { return r.expiry_status === 'near_expiry'; }).length;
                        return [rows.length, rows.length - expired - near, near, expired];
                    }
                },
                purchases: {
                    cards: [
                        { label: 'فواتير المشتريات', emoji: '🧾' },
                        { label: 'فواتير مكتملة', emoji: '✅', tone: 'green' },
                        { label: 'دفعات معلّقة', emoji: '💳', tone: 'amber' },
                        { label: 'قيمة المشتريات', emoji: '💰', money: true }
                    ],
                    load: async function () {
                        const d = await this.apiGet('purchases.list', { limit: 500 });
                        if (!d) return null;
                        const rows = d.purchases || [];
                        const done = rows.filter(function (r) { return r.status === 'completed'; }).length;
                        const due = rows.filter(function (r) { return r.payment_status === 'unpaid' || r.payment_status === 'partial'; }).length;
                        return [rows.length, done, due, this.money(sum(rows, 'total_amount'))];
                    }
                },
                suppliers: {
                    cards: [
                        { label: 'إجمالي الموردين', emoji: '🚚' },
                        { label: 'موردون نشطون', emoji: '✅', tone: 'green' },
                        { label: 'لديهم رصيد مستحق', emoji: '💳', tone: 'amber' },
                        { label: 'إجمالي الديون', emoji: '💰', money: true }
                    ],
                    load: async function () {
                        const d = await this.apiGet('suppliers.list', { limit: 500 });
                        if (!d) return null;
                        const rows = d.suppliers || [];
                        const active = rows.filter(function (r) { return Number(r.is_active) === 1; }).length;
                        const owing = rows.filter(function (r) { return this.num(r.current_balance) > 0; }, this);
                        return [rows.length, active, owing.length, this.money(sum(owing, 'current_balance'))];
                    }
                },
                customers: {
                    cards: [
                        { label: 'إجمالي العملاء', emoji: '👥' },
                        { label: 'عملاء نشطون', emoji: '✅', tone: 'green' },
                        { label: 'لديهم ديون', emoji: '💳', tone: 'amber' },
                        { label: 'إجمالي الديون', emoji: '💰', money: true }
                    ],
                    load: async function () {
                        const d = await this.apiGet('customers.list', { limit: 500 });
                        if (!d) return null;
                        const rows = d.customers || [];
                        const active = rows.filter(function (r) { return Number(r.is_active) === 1; }).length;
                        const owing = rows.filter(function (r) { return this.num(r.current_debt) > 0; }, this);
                        return [rows.length, active, owing.length, this.money(sum(owing, 'current_debt'))];
                    }
                },
                invoices: {
                    cards: [
                        { label: 'فواتير الشهر', emoji: '🧾' },
                        { label: 'فواتير مكتملة', emoji: '✅', tone: 'green' },
                        { label: 'فواتير بديون', emoji: '💳', tone: 'amber' },
                        { label: 'قيمة المبيعات', emoji: '💰', money: true }
                    ],
                    load: async function () {
                        const d = await this.apiGet('sales.list', { start_date: monthStart, end_date: this.localToday(), limit: 1000 });
                        if (!d) return null;
                        const rows = d.sales || [];
                        const done = rows.filter(function (r) { return r.status === 'completed'; }).length;
                        const owing = rows.filter(function (r) { return this.num(r.remaining_debt) > 0; }, this);
                        return [rows.length, done, owing.length, this.money(sum(rows, 'total_amount'))];
                    }
                },
                returns: {
                    cards: [
                        { label: 'إجمالي المرتجعات', emoji: '↩️' },
                        { label: 'مرتجعات نقدية', emoji: '💰', tone: 'green' },
                        { label: 'عمليات استبدال', emoji: '🔄', tone: 'amber' },
                        { label: 'قيمة المسترد', emoji: '🧾', money: true }
                    ],
                    load: async function () {
                        const d = await this.apiGet('returns.list');
                        if (!d) return null;
                        const rows = d.returns || [];
                        const refund = rows.filter(function (r) { return r.return_type === 'refund'; }).length;
                        const exchange = rows.filter(function (r) { return r.return_type === 'exchange'; }).length;
                        return [rows.length, refund, exchange, this.money(sum(rows, 'total_refund_amount'))];
                    }
                },
                held: {
                    cards: [
                        { label: 'فواتير معلّقة', emoji: '⏳', tone: 'amber' },
                        { label: 'فواتير اليوم', emoji: '📅' },
                        { label: 'إجمالي الأصناف', emoji: '📦' },
                        { label: 'قيمة المعلّق', emoji: '💰', money: true }
                    ],
                    load: async function () {
                        const d = await this.apiGet('pos.held_invoices');
                        if (!d) return null;
                        const rows = d.held_invoices || [];
                        const today = this.localToday();
                        const todayCount = rows.filter(function (r) { return this.dateOnly(r.sale_date) === today; }, this).length;
                        return [rows.length, todayCount, sum(rows, 'items_count'), this.money(sum(rows, 'total_amount'))];
                    }
                },
                expenses: {
                    cards: [
                        { label: 'إجمالي المصروفات', emoji: '🧾' },
                        { label: 'مصروفات اليوم', emoji: '📅', tone: 'amber' },
                        { label: 'تصنيفات المصروفات', emoji: '🗂️' },
                        { label: 'إجمالي القيمة', emoji: '💰', money: true }
                    ],
                    load: async function () {
                        const results = await Promise.all([
                            this.apiGet('expenses.list', { start_date: '2000-01-01', end_date: this.localToday(), limit: 1000 }),
                            this.apiGet('expenses.categories')
                        ]);
                        if (!results[0]) return null;
                        const rows = results[0].expenses || [];
                        const today = this.localToday();
                        const todayCount = rows.filter(function (r) { return this.dateOnly(r.expense_date) === today; }, this).length;
                        return [rows.length, todayCount, ((results[1] && results[1].categories) || []).length, this.money(sum(rows, 'amount_in_base'))];
                    }
                },
                cash_register: {
                    cards: [
                        { label: 'ورديات اليوم', emoji: '🕒' },
                        { label: 'ورديات مفتوحة', emoji: '✅', tone: 'green' },
                        { label: 'النقد المتوقع', emoji: '💰', money: true },
                        { label: 'أجهزة الصندوق', emoji: '🧮' }
                    ],
                    load: async function () {
                        const results = await Promise.all([
                            this.apiGet('cash_register.sessions', { limit: 500 }),
                            this.apiGet('cash_register.status')
                        ]);
                        if (!results[0]) return null;
                        const rows = results[0].sessions || [];
                        const today = this.localToday();
                        const todayCount = rows.filter(function (r) { return this.dateOnly(r.opened_at) === today; }, this).length;
                        const open = rows.filter(function (r) { return r.status === 'open'; });
                        const session = results[1] && results[1].session ? results[1].session : (open.length ? open[0] : null);
                        return [todayCount, open.length, this.money(session ? session.expected_cash : 0), ((results[1] && results[1].registers) || []).length];
                    }
                },
                expiry: {
                    cards: [
                        { label: 'تنبيهات الصلاحية', emoji: '⏳', tone: 'amber' },
                        { label: 'دفعات منتهية', emoji: '🚫', tone: 'red' },
                        { label: 'قريبة الانتهاء', emoji: '⚠️', tone: 'amber' },
                        { label: 'نافذة التنبيه', emoji: '🗓️' }
                    ],
                    load: async function () {
                        const d = await this.apiGet('expiry.alerts');
                        if (!d) return null;
                        const counts = d.counts || {};
                        return [(d.alerts || []).length, counts.expired || 0, counts.near_expiry || 0, (d.alert_days || 30) + ' يوم'];
                    }
                },
                doctors: {
                    cards: [
                        { label: 'إجمالي الأطباء', emoji: '👨‍⚕️' },
                        { label: 'أطباء نشطون', emoji: '✅', tone: 'green' },
                        { label: 'غير نشطين', emoji: '⏸️', tone: 'amber' },
                        { label: 'نسبة النشاط', emoji: '📊' }
                    ],
                    load: async function () {
                        const d = await this.apiGet('doctors.list');
                        if (!d) return null;
                        const rows = d.doctors || [];
                        const active = rows.filter(function (r) { return Number(r.is_active) === 1; }).length;
                        const rate = rows.length ? Math.round(active * 100 / rows.length) : 0;
                        return [rows.length, active, rows.length - active, rate + '%'];
                    }
                },
                prescriptions: {
                    cards: [
                        { label: 'إجمالي الروشتات', emoji: '🧾' },
                        { label: 'بانتظار الصرف', emoji: '⏳', tone: 'amber' },
                        { label: 'تم صرفها', emoji: '✅', tone: 'green' },
                        { label: 'ملغاة', emoji: '🚫', tone: 'red' }
                    ],
                    load: async function () {
                        const d = await this.apiGet('prescriptions.list', { limit: 1000 });
                        if (!d) return null;
                        const rows = d.prescriptions || [];
                        const count = function (status) {
                            return rows.filter(function (r) { return r.status === status; }).length;
                        };
                        return [rows.length, count('issued'), count('dispensed'), count('cancelled')];
                    }
                },
                pharmacy: {
                    cards: [
                        { label: 'أوامر التوريد', emoji: '📦' },
                        { label: 'جرد مفتوح', emoji: '📋', tone: 'amber' },
                        { label: 'تلفيات مسجلة', emoji: '⚠️', tone: 'red' },
                        { label: 'إعدادات مفعّلة', emoji: '⚙️', tone: 'green' }
                    ],
                    load: async function () {
                        const results = await Promise.all([
                            this.apiGet('purchase_orders.list'),
                            this.apiGet('stocktake.list'),
                            this.apiGet('damage.list'),
                            this.apiGet('pharmacy.meta')
                        ]);
                        if (!results[0] && !results[1] && !results[2] && !results[3]) return null;
                        const orders = (results[0] && results[0].orders) || [];
                        const sessions = (results[1] && results[1].sessions) || [];
                        const damage = (results[2] && results[2].records) || [];
                        const flags = (results[3] && results[3].settings) || {};
                        const enabled = Object.keys(flags).filter(function (key) { return !!flags[key]; }).length;
                        return [orders.length, sessions.filter(function (r) { return r.status !== 'approved'; }).length, damage.length, enabled];
                    }
                },
                reports: {
                    cards: [
                        { label: 'الأدوية المسجلة', emoji: '💊' },
                        { label: 'فواتير اليوم', emoji: '🧾', tone: 'green' },
                        { label: 'قيمة المخزون', emoji: '💰', money: true },
                        { label: 'أصناف منخفضة', emoji: '📉', tone: 'amber' }
                    ],
                    load: async function () {
                        const d = await this.apiGet('dashboard.metrics');
                        if (!d) return null;
                        const inv = d.inventory || {};
                        const today = d.today || {};
                        return [inv.total_products || 0, today.invoice_count || 0, this.money(inv.inventory_valuation || 0), inv.low_stock_count || 0];
                    }
                },
                users: {
                    cards: [
                        { label: 'إجمالي المستخدمين', emoji: '👤' },
                        { label: 'حسابات نشطة', emoji: '✅', tone: 'green' },
                        { label: 'حسابات معلّقة', emoji: '⏸️', tone: 'amber' },
                        { label: 'الأدوار الوظيفية', emoji: '🛡️' }
                    ],
                    load: async function () {
                        const results = await Promise.all([
                            this.apiGet('users.list'),
                            this.apiGet('users.roles')
                        ]);
                        if (!results[0]) return null;
                        const rows = results[0].users || [];
                        const active = rows.filter(function (r) { return Number(r.is_active) === 1; }).length;
                        return [rows.length, active, rows.length - active, ((results[1] && results[1].roles) || []).length];
                    }
                },
                audit: {
                    cards: [
                        { label: 'عمليات مسجّلة', emoji: '📜' },
                        { label: 'عمليات اليوم', emoji: '📅', tone: 'green' },
                        { label: 'مستخدمون نشطون', emoji: '👥' },
                        { label: 'أنواع العمليات', emoji: '🗂️', tone: 'amber' }
                    ],
                    load: async function () {
                        const d = await this.apiGet('users.audit_logs', { limit: 1000 });
                        if (!d) return null;
                        const rows = d.logs || [];
                        const today = this.localToday();
                        const todayCount = rows.filter(function (r) { return this.dateOnly(r.created_at) === today; }, this).length;
                        const users = {};
                        const actions = {};
                        rows.forEach(function (r) { users[r.user_id] = 1; actions[r.action_type] = 1; });
                        return [rows.length, todayCount, Object.keys(users).length, Object.keys(actions).length];
                    }
                },
                backups: {
                    cards: [
                        { label: 'النسخ الاحتياطية', emoji: '💾' },
                        { label: 'نسخ مكتملة', emoji: '✅', tone: 'green' },
                        { label: 'نسخ هذا الشهر', emoji: '📅', tone: 'amber' },
                        { label: 'الحجم الإجمالي', emoji: '📦' }
                    ],
                    load: async function () {
                        const d = await this.apiGet('backups.list', { limit: 500 });
                        if (!d) return null;
                        const rows = d.backups || [];
                        const done = rows.filter(function (r) { return r.status === 'completed'; }).length;
                        const month = this.localToday().substring(0, 7);
                        const thisMonth = rows.filter(function (r) { return this.dateOnly(r.created_at).substring(0, 7) === month; }, this).length;
                        const bytes = sum(rows, 'file_size_bytes');
                        return [rows.length, done, thisMonth, (bytes / 1048576).toFixed(1) + ' MB'];
                    }
                },
                settings: {
                    cards: [
                        { label: 'الفروع', emoji: '🏬' },
                        { label: 'أجهزة الصندوق', emoji: '🧮' },
                        { label: 'تصنيفات المصروفات', emoji: '🗂️', tone: 'amber' },
                        { label: 'العلامات التجارية', emoji: '🏷️', tone: 'green' }
                    ],
                    load: async function () {
                        const d = await this.apiGet('settings.get');
                        if (!d) return null;
                        return [
                            (d.branches || []).length,
                            (d.cash_registers || []).length,
                            (d.expense_categories || []).length,
                            (d.brands || []).length
                        ];
                    }
                }
            };
        },

        warmBeatSvg() {
            return '<svg class="pgx-hero-beat" viewBox="0 0 600 92" preserveAspectRatio="none" aria-hidden="true">'
                + '<path vector-effect="non-scaling-stroke" d="M0 52 H40 L48 48 L56 56 L64 26 L74 72 L82 48 L90 52 H150 L158 48 L166 56 L174 26 L184 72 L192 48 L200 52 H260 L268 48 L276 56 L284 26 L294 72 L302 48 L310 52 H370 L378 48 L386 56 L394 26 L404 72 L412 48 L420 52 H480 L488 48 L496 56 L504 26 L514 72 L522 48 L530 52 H600"/>'
                + '<circle class="peak" vector-effect="non-scaling-stroke" cx="64" cy="26" r="2.6"/>'
                + '<circle class="peak" vector-effect="non-scaling-stroke" cx="174" cy="26" r="2.6"/>'
                + '<circle class="peak" vector-effect="non-scaling-stroke" cx="284" cy="26" r="2.6"/>'
                + '<circle class="peak" vector-effect="non-scaling-stroke" cx="394" cy="26" r="2.6"/>'
                + '<circle class="peak" vector-effect="non-scaling-stroke" cx="504" cy="26" r="2.6"/>'
                + '</svg>';
        },

        ensureWarmPage(target) {
            if (target === 'dashboard' || target === 'pos' || target === 'products' || target === 'inventory') return;
            const view = document.getElementById('view-' + target);
            if (!view) return;
            const config = this.warmStatConfig()[target];
            if (!config) return;
            const page = view.querySelector('.pgx-page');
            if (!page) return;
            view.classList.add('wc-view');
            const hero = page.querySelector('.pgx-hero');
            if (hero && !hero.querySelector('.pgx-hero-beat')) {
                hero.insertAdjacentHTML('afterbegin', this.warmBeatSvg());
            }
            let stats = page.querySelector('.wcx-stats');
            if (!stats) {
                stats = document.createElement('section');
                stats.className = 'wcx-stats';
                stats.setAttribute('aria-label', 'إحصاء الصفحة');
                stats.innerHTML = config.cards.map(function (card, index) {
                    return '<div class="wcx-stat' + (card.tone ? ' is-' + card.tone : '') + '">'
                        + '<span class="wcx-stat-em" aria-hidden="true">' + card.emoji + '</span>'
                        + '<small>' + card.label + '</small>'
                        + '<b id="wcx-' + target + '-' + index + '"' + (card.money ? ' class="is-money"' : '') + '>—</b>'
                        + '</div>';
                }).join('');
                if (hero) hero.insertAdjacentElement('afterend', stats);
                else page.insertBefore(stats, page.firstChild);
            }
            this.loadWarmStats(target, config);
        },

        async loadWarmStats(target, config) {
            const values = await config.load.call(this);
            if (!values) return;
            for (let i = 0; i < values.length; i += 1) {
                const el = document.getElementById('wcx-' + target + '-' + i);
                if (el) el.textContent = String(values[i]);
            }
        },

        loadSection(target) {
            this.ensureWarmPage(target);
            const map = {
                dashboard: 'loadDashboard',
                pos: 'loadPos',
                products: 'loadProducts',
                inventory: 'loadInventory',
                purchases: 'loadPurchases',
                suppliers: 'loadSuppliers',
                customers: 'loadCustomers',
                invoices: 'loadInvoices',
                returns: 'loadReturns',
                held: 'loadHeldInvoices',
                expenses: 'loadExpenses',
                cash_register: 'loadCashRegister',
                batches: 'loadBatches',
                expiry: 'loadExpiry',
                doctors: 'loadDoctors',
                prescriptions: 'loadPrescriptions',
                pharmacy: 'loadPharmacy',
                reports: 'loadReportTypes',
                users: 'loadUsers',
                audit: 'loadAuditLogs',
                backups: 'loadBackups',
                settings: 'loadSettings'
            };
            const method = map[target];
            if (method && typeof this[method] === 'function') {
                this[method]();
            }
        },

        onTab(group, tab) {
            if (group === 'inventory') {
                if (tab === 'stock') this.loadInventory();
                else if (tab === 'low') this.loadInventoryLow();
                else if (tab === 'movements') this.loadInventoryMovements();
                else if (tab === 'adjust') this.loadProductOptions('adj-product-id');
                else if (tab === 'transfer') this.loadInventoryTransferOptions();
            } else if (group === 'pharmacy') {
                if (tab === 'stocktake') this.loadPharmacyStocktake();
                else if (tab === 'damage') this.loadPharmacyDamage();
                else if (tab === 'units') this.loadPharmacyUnits();
                else if (tab === 'patients') this.loadPharmacyPatients();
                else if (tab === 'review') this.loadPharmacyReview();
                else if (tab === 'supply') this.loadPharmacySupply();
            } else if (group === 'users') {
                if (tab === 'list') this.loadUsers();
                else if (tab === 'roles') this.loadRoles();
            } else if (group === 'settings') {
                if (tab === 'pharmacy' || tab === 'store') {
                    this.renderSettingsStore();
                    this.renderSettingsPharmacy();
                }
                else if (tab === 'categories') this.renderSettingsCategories();
                else if (tab === 'brands') this.renderSettingsBrands();
                else if (tab === 'branches') this.renderSettingsBranches();
                else if (tab === 'registers') this.renderSettingsRegisters();
                else if (tab === 'expense_categories') this.renderSettingsExpenseCategories();
                else if (tab === 'owner') this.loadOwnerCleanupPanel();
            }
        },

        initTheme() {
            const self = this;
            let theme = 'light';
            try {
                const saved = localStorage.getItem('levi-theme');
                if (saved === 'dark' || saved === 'light') {
                    theme = saved;
                } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    theme = 'dark';
                }
            } catch (e) {
                theme = document.body.dataset.theme === 'dark' ? 'dark' : 'light';
            }
            document.body.dataset.theme = theme;
            const btn = document.getElementById('theme-toggle');
            if (btn) {
                btn.addEventListener('click', function () {
                    const next = document.body.dataset.theme === 'dark' ? 'light' : 'dark';
                    document.body.dataset.theme = next;
                    try { localStorage.setItem('levi-theme', next); } catch (e) { /* ignore */ }
                    self.syncThemeButton();
                });
            }
            this.syncThemeButton();
        },

        syncThemeButton() {
            const btn = document.getElementById('theme-toggle');
            if (!btn) return;
            const dark = document.body.dataset.theme === 'dark';
            const label = dark ? 'التبديل إلى الوضع الفاتح' : 'التبديل إلى الوضع الليلي';
            btn.setAttribute('aria-label', label);
            btn.setAttribute('title', label);
            btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
        },

        bindTopbarUserMenu() {
            const wrap = document.getElementById('topbar-user-wrap');
            const btn = document.getElementById('topbar-user-btn');
            const menu = document.getElementById('topbar-menu');
            if (!wrap || !btn || !menu) return;
            const close = function () {
                menu.classList.remove('open');
                btn.setAttribute('aria-expanded', 'false');
            };
            btn.addEventListener('click', function (event) {
                event.stopPropagation();
                const open = menu.classList.toggle('open');
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('click', function (event) {
                if (!wrap.contains(event.target)) close();
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') close();
            });
            menu.querySelectorAll('.topbar-menu-item').forEach(function (item) {
                item.addEventListener('click', close);
            });
        },

        startClock() {
            const dateEl = document.getElementById('local-clock-date');
            const timeEl = document.getElementById('local-clock-time');
            if (!dateEl && !timeEl) return;
            const self = this;
            const boot = this.boot();
            const timezone = boot.timezone || 'Africa/Tripoli';
            const baseMs = Number(boot.serverNowMs) || Date.now();
            const loadedAt = Date.now();
            let dateFmt;
            let timeFmt;
            let wallFmt;
            try {
                dateFmt = new Intl.DateTimeFormat('ar-LY', {
                    timeZone: timezone, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
                });
                timeFmt = new Intl.DateTimeFormat('en-GB', {
                    timeZone: timezone, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
                });
                wallFmt = new Intl.DateTimeFormat('en-CA', {
                    timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit',
                    hour: '2-digit', minute: '2-digit', hour12: false
                });
            } catch (e) {
                dateFmt = new Intl.DateTimeFormat('ar-LY', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
                timeFmt = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
                wallFmt = new Intl.DateTimeFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false });
            }
            const tick = function () {
                const now = new Date(baseMs + (Date.now() - loadedAt));
                if (dateEl) dateEl.textContent = dateFmt.format(now);
                if (timeEl) timeEl.textContent = timeFmt.format(now);
                try {
                    const parts = wallFmt.formatToParts(now);
                    const get = function (type) {
                        const found = parts.find(function (p) { return p.type === type; });
                        return found ? found.value : '';
                    };
                    let hour = get('hour');
                    if (hour === '24') hour = '00';
                    self.state.clockWall = get('year') + '-' + get('month') + '-' + get('day') + 'T' + hour + ':' + get('minute');
                    self.state.clockMs = now.getTime();
                } catch (e) {
                    self.state.clockWall = '';
                }
            };
            tick();
            setInterval(tick, 1000);
        },

        openClockModal() {
            if (!this.boot().isOwner) {
                this.toast('ضبط الساعة والتاريخ مخصص للمدير العام فقط.', 'warning');
                return;
            }
            const wall = this.state.clockWall || '';
            const offset = Number(this.boot().clockOffset) || 0;
            const offsetText = offset === 0
                ? 'الساعة مضبوطة على وقت النظام.'
                : 'فرق الضبط الحالي: ' + (offset > 0 ? '+' : '') + Math.round(offset / 60) + ' دقيقة.';
            const body = '<div class="form-grid">'
                + '<div><label>التاريخ والوقت المعروض *</label><input type="datetime-local" id="clock-local" class="form-control" value="' + this.escapeHtml(wall) + '"></div>'
                + '</div>'
                + '<div class="muted mt-2" style="font-size:12px;line-height:1.7;">'
                + 'يتم ضبط ساعة العرض العلوية على الوقت الذي تختاره، ويُحفظ الضبط بعد إعادة تحميل الصفحة. '
                + 'المنطقة الزمنية الحالية: <strong>' + this.escapeHtml(this.boot().timezone || 'Africa/Tripoli') + '</strong>.'
                + '<br>' + this.escapeHtml(offsetText)
                + '</div>';
            const footer = '<button type="button" class="btn btn-outline" onclick="LeviPOS.submitClock(true)">إعادة الضبط على وقت النظام</button>'
                + '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إلغاء</button>'
                + '<button type="button" class="btn btn-primary" onclick="LeviPOS.submitClock(false)">حفظ الوقت</button>';
            this.openModal('ضبط الساعة', body, footer);
        },

        async submitClock(reset) {
            const payload = {};
            if (reset) {
                payload.reset = 1;
            } else {
                const el = document.getElementById('clock-local');
                const value = el ? el.value.trim() : '';
                if (!value) {
                    this.toast('يرجى تحديد التاريخ والوقت.', 'warning');
                    return;
                }
                payload.local_datetime = value;
            }
            const result = await this.apiPost('settings.clock_adjust', payload);
            if (!result) return;
            this.toast(reset ? 'تمت إعادة ضبط الساعة على وقت النظام.' : 'تم ضبط الساعة.', 'success');
            this.closeModal();
            window.setTimeout(function () { window.location.reload(); }, 350);
        },

        baseCommands() {
            const defs = [
                { target: 'dashboard', perm: 'dashboard', label: 'لوحة التحكم', icon: '⌂', keywords: 'home dashboard main' },
                { target: 'pos', perm: 'pos', label: 'فتح نقطة بيع', icon: '🧾', keywords: 'sale invoicepos بيع فاتورة' },
                { target: 'products', perm: 'products', label: 'بحث عن دواء', icon: '💊', keywords: 'product medicine دواء منتجات' },
                { target: 'prescriptions', perm: 'prescriptions', label: 'مراجعة الروشتات', icon: '🩺', keywords: 'prescription rx روشتة' },
                { target: 'customers', perm: 'customers', label: 'المرضى والعملاء', icon: '👥', keywords: 'patient customer مريض عميل' },
                { target: 'expiry', perm: 'expiry', label: 'انتهاء الصلاحية', icon: '⚠', keywords: 'expiry batch دفعة منتهي' },
                { target: 'inventory', perm: 'inventory', label: 'المخزون والجرد', icon: '📦', keywords: 'inventory stock مخزون' },
                { target: 'batches', perm: 'batches', label: 'دفعات الأدوية', icon: '🧮', keywords: 'batch دفعات' },
                { target: 'purchases', perm: 'purchases', label: 'المشتريات', icon: '🚚', keywords: 'purchase توريد مورد' },
                { target: 'invoices', perm: 'invoices', label: 'الفواتير', icon: '📄', keywords: 'invoice فاتورة' },
                { target: 'reports', perm: 'reports', label: 'التقارير', icon: '📈', keywords: 'report تقرير' },
                { target: 'settings', perm: 'settings', label: 'الإعدادات', icon: '⚙', keywords: 'settings إعدادات' }
            ];
            return defs.filter(function (def) {
                return !def.perm || this.perm(def.perm);
            }, this).map(function (def) {
                return {
                    id: 'nav-' + def.target,
                    icon: def.icon,
                    title: def.label,
                    hint: 'صفحة',
                    type: 'nav',
                    target: def.target,
                    keywords: def.label + ' ' + def.keywords
                };
            }, this);
        },

        bindCommandPalette() {
            const input = document.getElementById('cmd-search');
            if (!input) return;
            const self = this;
            input.addEventListener('input', function () {
                if (self.state.timers.cmdSearch) clearTimeout(self.state.timers.cmdSearch);
                self.state.timers.cmdSearch = setTimeout(function () { self.searchCommands(); }, 140);
            });
            const overlay = document.getElementById('command-palette');
            if (overlay) {
                overlay.addEventListener('click', function (event) {
                    if (event.target === overlay) self.closeCommandPalette();
                });
            }
            this.state.commandItems = this.baseCommands().slice(0, 8);
            this.runCommandIndex();
        },

        openCommandPalette() {
            const overlay = document.getElementById('command-palette');
            const input = document.getElementById('cmd-search');
            if (!overlay || !input) return;
            overlay.hidden = false;
            overlay.classList.add('open');
            input.value = '';
            this.state.commandIndex = 0;
            this.searchCommands();
            setTimeout(function () { input.focus(); }, 0);
        },

        closeCommandPalette() {
            const overlay = document.getElementById('command-palette');
            if (!overlay) return;
            overlay.classList.remove('open');
            overlay.hidden = true;
            this.state.commandToken++;
        },

        async searchCommands() {
            const input = document.getElementById('cmd-search');
            const term = input ? String(input.value || '').trim() : '';
            const lower = term.toLowerCase();
            const token = ++this.state.commandToken;
            let items = this.baseCommands().filter(function (item) {
                return !term || String(item.title + ' ' + item.keywords).toLowerCase().indexOf(lower) !== -1;
            });

            if (term.length >= 2) {
                const requests = [];
                if (this.perm('products')) {
                    requests.push(this.apiGet('products.list', { q: term, limit: 6 }).then((res) => {
                        const rows = res && Array.isArray(res.items) ? res.items : [];
                        return rows.slice(0, 6).map((p) => ({
                            id: 'product-' + p.id,
                            icon: '💊',
                            title: String(p.name || p.product_name || ''),
                            hint: 'دواء' + (p.sku ? ' · ' + p.sku : ''),
                            type: 'product',
                            valueId: Number(p.id),
                            keywords: [p.name, p.active_ingredient, p.sku].join(' ')
                        }));
                    }));
                }
                if (this.perm('customers')) {
                    requests.push(this.apiGet('customers.list', { q: term, limit: 6 }).then((res) => {
                        const rows = res && Array.isArray(res.customers) ? res.customers : [];
                        return rows.slice(0, 6).map((c) => ({
                            id: 'customer-' + c.id,
                            icon: '👤',
                            title: String(c.name || ''),
                            hint: 'مريض / عميل',
                            type: 'customer',
                            valueId: Number(c.id),
                            keywords: [c.name, c.phone].join(' ')
                        }));
                    }));
                }
                if (this.perm('prescriptions')) {
                    requests.push(this.apiGet('prescriptions.list', { q: term }).then((res) => {
                        const rows = res && Array.isArray(res.prescriptions) ? res.prescriptions : [];
                        return rows.slice(0, 6).map((r) => ({
                            id: 'prescription-' + r.id,
                            icon: '🩺',
                            title: 'روشتة ' + String(r.prescription_number || ('#' + r.id)),
                            hint: String(r.patient_name || r.customer_name || ''),
                            type: 'prescription',
                            valueId: Number(r.id),
                            keywords: [r.prescription_number, r.patient_name, r.customer_name].join(' ')
                        }));
                    }));
                }
                if (requests.length) {
                    const groups = await Promise.all(requests);
                    if (token !== this.state.commandToken) return;
                    groups.forEach(function (group) {
                        group.forEach(function (item) {
                            if (item.title) items.push(item);
                        });
                    });
                }
            }

            const unique = [];
            const seen = {};
            items.forEach(function (item) {
                if (!seen[item.id]) {
                    seen[item.id] = true;
                    unique.push(item);
                }
            });
            this.state.commandItems = unique.slice(0, 12);
            this.state.commandIndex = 0;
            this.runCommandIndex();
        },

        runCommandIndex() {
            const box = document.getElementById('cmd-results');
            if (!box) return;
            const items = this.state.commandItems || [];
            if (!items.length) {
                box.innerHTML = '<div class="command-palette-empty">لا توجد نتائج مطابقة.</div>';
                return;
            }
            box.innerHTML = items.map(function (item, index) {
                return '<button type="button" role="option" aria-selected="' + (index === this.state.commandIndex ? 'true' : 'false') + '"'
                    + ' class="command-palette-item' + (index === this.state.commandIndex ? ' is-active' : '') + '"'
                    + ' data-command-index="' + index + '"'
                    + ' onmouseenter="LeviPOS.setCommandIndex(' + index + ')"'
                    + ' onclick="LeviPOS.executeCommandIndex(' + index + ')">'
                    + '<span class="cmd-ic" aria-hidden="true">' + this.escapeHtml(item.icon) + '</span>'
                    + '<span>' + this.escapeHtml(item.title) + '</span>'
                    + '<small>' + this.escapeHtml(item.hint) + '</small>'
                    + '</button>';
            }, this).join('');
        },

        setCommandIndex(index) {
            const total = (this.state.commandItems || []).length;
            if (!total) return;
            const next = ((index % total) + total) % total;
            if (next === this.state.commandIndex) return;
            this.state.commandIndex = next;
            const nodes = document.querySelectorAll('#cmd-results .command-palette-item');
            nodes.forEach(function (node, i) {
                node.classList.toggle('is-active', i === next);
                node.setAttribute('aria-selected', i === next ? 'true' : 'false');
            });
            if (nodes[next] && nodes[next].scrollIntoView) nodes[next].scrollIntoView({ block: 'nearest' });
        },

        executeCommandIndex(index) {
            const item = (this.state.commandItems || [])[index];
            if (!item) return;
            this.closeCommandPalette();
            if (item.type === 'nav') {
                this.navigate(item.target);
                if (item.target === 'dashboard') this.loadDashboard();
            } else if (item.type === 'product') {
                if (this.perm('products_manage')) this.openProductModal(item.valueId);
                else this.navigate('products');
            } else if (item.type === 'customer') {
                if (this.perm('customers')) this.openCustomerModal(item.valueId);
                else this.navigate('customers');
            } else if (item.type === 'prescription') {
                if (this.perm('prescriptions')) this.openPrescriptionModal(item.valueId);
                else this.navigate('prescriptions');
            }
        },

        bindGlobalEvents() {
            const self = this;
            const palette = document.getElementById('command-palette');
            const paletteOpen = function () {
                return !!palette && palette.classList.contains('open');
            };
            document.addEventListener('keydown', function (event) {
                if ((event.ctrlKey || event.metaKey) && String(event.key).toLowerCase() === 'k') {
                    event.preventDefault();
                    self.openCommandPalette();
                    return;
                }
                if (paletteOpen()) {
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        self.closeCommandPalette();
                    } else if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        self.setCommandIndex(self.state.commandIndex + 1);
                    } else if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        self.setCommandIndex(self.state.commandIndex - 1);
                    } else if (event.key === 'Enter') {
                        event.preventDefault();
                        self.executeCommandIndex(self.state.commandIndex);
                    }
                    return;
                }
                if (event.key === 'Escape') {
                    self.closeModal();
                } else if (event.key === 'F2') {
                    event.preventDefault();
                    self.navigate('pos');
                    setTimeout(function () {
                        const input = document.getElementById('pos-search-input');
                        if (input) input.focus();
                    }, 150);
                } else if (event.key === 'F4') {
                    event.preventDefault();
                    self.submitPosSale(true);
                } else if (event.key === 'F8') {
                    event.preventDefault();
                    self.submitPosSale(false);
                }
            });
            this.bindCommandPalette();
        },

        async refreshSessionBadge() {
            const data = await this.apiGet('pos.session_status');
            if (!data) return;
            this.state.session = data.my_session || data.session || null;
            this.state.registers = data.registers || [];
            const top = document.getElementById('topbar-session-badge');
            const pos = document.getElementById('pos-session-badge');
            if (top) {
                top.className = 'topbar-pill topbar-session ' + (this.state.session ? 'is-open' : 'is-closed');
                top.title = this.state.session
                    ? 'وردية مفتوحة: ' + (this.state.session.session_number || '')
                    : 'لا توجد وردية مفتوحة';
                const topText = top.querySelector('.topbar-session-text');
                if (topText) {
                    topText.textContent = this.state.session ? 'وردية مفتوحة' : 'لا توجد وردية مفتوحة';
                }
            }
            if (pos) {
                if (this.state.session) {
                    pos.className = 'pill pill-green';
                    pos.textContent = 'وردية مفتوحة: ' + (this.state.session.session_number || '');
                } else {
                    pos.className = 'pill pill-amber';
                    pos.innerHTML = 'لا توجد وردية مفتوحة '
                        + '<button type="button" class="btn btn-sm btn-success" style="margin-right:8px;" onclick="LeviPOS.openShiftModal()">فتح وردية</button>';
                }
            }
        },

        async loadReferenceData() {
            if (this.state.categories.length && this.state.brands.length) return;
            const results = await Promise.all([
                this.apiGet('pos.categories'),
                this.apiGet('pos.brands')
            ]);
            if (results[0]) this.state.categories = results[0].categories || [];
            if (results[1]) this.state.brands = results[1].brands || [];
        },

        /* =================================================================
         * Auth
         * ================================================================= */
        async logout() {
            // Ask the server for the live shift status so a shift closed in
            // another tab never blocks logout, and an open one always does.
            const status = await this.apiGet('pos.session_status');
            const myShift = status ? (status.my_session || null) : null;
            const enforceShiftClosing = !this.boot() || this.boot().enforceShiftClosing !== false;
            if (myShift && enforceShiftClosing) {
                this.toast('لا يمكن تسجيل الخروج والوردية مفتوحة (' + (myShift.session_number || '') + '). يرجى إغلاق الوردية أولاً.', 'warning');
                return;
            }
            if (!window.confirm('هل تريد تسجيل الخروج؟')) return;
            const result = await this.apiPost('auth.logout');
            if (result === null) return; // Stay signed in when the server refuses.
            window.location.href = 'login.php';
        },

        openChangePassword(force) {
            this.forcePasswordChange = !!force;
            const body = ''
                + (force ? '<div class="badge badge-warning w-100" style="display:block;padding:10px;margin-bottom:12px;">لأمان حسابك، يجب تغيير كلمة المرور الافتراضية قبل المتابعة.</div>' : '')
                + '<div class="form-grid">'
                + '<div><label>كلمة المرور الحالية *</label><input type="password" id="cp-current" class="form-control" autocomplete="current-password"></div>'
                + '<div><label>كلمة المرور الجديدة *</label><input type="password" id="cp-new" class="form-control" autocomplete="new-password"></div>'
                + '<div><label>تأكيد كلمة المرور الجديدة *</label><input type="password" id="cp-confirm" class="form-control" autocomplete="new-password"></div>'
                + '</div>';
            const footer = (force ? '' : '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إلغاء</button>')
                + '<button type="button" class="btn btn-primary" onclick="LeviPOS.submitChangePassword()">حفظ</button>';
            this.openModal('تغيير كلمة المرور', body, footer, 'narrow');
        },

        async submitChangePassword() {
            const current = document.getElementById('cp-current').value;
            const next = document.getElementById('cp-new').value;
            const confirm = document.getElementById('cp-confirm').value;
            if (!current || !next) { this.toast('يرجى إدخال كلمة المرور الحالية والجديدة.', 'warning'); return; }
            if (next !== confirm) { this.toast('تأكيد كلمة المرور غير مطابق.', 'warning'); return; }
            const result = await this.apiPost('auth.change_password', { current_password: current, new_password: next });
            if (result) {
                this.forcePasswordChange = false;
                const boot = this.boot();
                if (boot && boot.user) boot.user.must_change_password = 0;
                this.toast('تم تغيير كلمة المرور بنجاح.', 'success');
                this.closeModal();
            }
        },

        /* =================================================================
         * Dashboard
         * ================================================================= */
        async loadDashboard() {
            const [data, expiryData] = await Promise.all([
                this.apiGet('dashboard.metrics'),
                this.apiGet('expiry.alerts')
            ]);
            if (!data) return;
            const expiryCounts = expiryData && expiryData.counts ? expiryData.counts : { expired: 0, near_expiry: 0 };
            const pharma = data.pharmacy || {};
            const rxSummary = pharma.prescriptions || {};
            const pendingRx = this.num(rxSummary.pending);
            const dispensedRx = this.num(rxSummary.dispensed_today);
            const newPatients = this.num((pharma.patients || {}).new_today);
            const welcomeEl = document.getElementById('dashboard-welcome');
            if (welcomeEl) {
                welcomeEl.textContent = pendingRx > 0
                    ? 'لديك ' + this.arabicCount(pendingRx, {
                        one: 'روشتة واحدة بانتظار المراجعة',
                        two: 'روشتتان بانتظار المراجعة',
                        few: 'روشتات بانتظار المراجعة',
                        many: 'روشتة بانتظار المراجعة'
                    }) + '، و' + this.arabicCount(dispensedRx, {
                        one: 'روشتة تم صرفها اليوم',
                        two: 'روشتتان تم صرفهما اليوم',
                        few: 'روشتات تم صرفها اليوم',
                        many: 'روشتة تم صرفها اليوم'
                    }) + '. راجعها لتأكيد الصرف بثقة.'
                    : 'لا توجد روشتات معلّقة الآن — صيدليتك جاهزة لخدمة المرضى، و' + this.arabicCount(newPatients, {
                        one: 'مريض جديد سُجّل اليوم',
                        two: 'مريضان جديدان سُجّلا اليوم',
                        few: 'مرضى جدد سُجّلوا اليوم',
                        many: 'مريض جديد سُجّل اليوم'
                    }) + '.';
            }
            const quickExpiryEl = document.getElementById('dashboard-quick-expiry');
            if (quickExpiryEl) {
                const attention = this.num(expiryCounts.expired) + this.num(expiryCounts.near_expiry);
                quickExpiryEl.textContent = attention > 0
                    ? this.arabicCount(attention, {
                        one: 'دفعة واحدة تحتاج صرفًا أولًا',
                        two: 'دفعتان تحتاجان صرفًا أولًا',
                        few: 'دفعات تحتاج صرفًا أولًا',
                        many: 'دفعة تحتاج صرفًا أولًا'
                    })
                    : 'لا توجد دفعات حرجة حاليًا';
            }
            const forecastSummary = pharma.forecast || {};
            const forecastCritical = this.num(forecastSummary.critical);
            const forecastLow = this.num(forecastSummary.low);
            const forecastDetail = document.getElementById('dashboard-quick-forecast-detail');
            if (forecastDetail) {
                forecastDetail.textContent = forecastCritical > 0
                    ? this.arabicCount(forecastCritical, {
                        one: 'دواء واحد متوقع نفاده',
                        two: 'دواءان متوقعان نفادهما',
                        few: 'أدوية متوقع نفادها',
                        many: 'دواء متوقع نفاده'
                    }) + ' خلال ٣٠ يومًا'
                    : forecastLow > 0
                        ? this.arabicCount(forecastLow, {
                            one: 'دواء واحد منخفض المخزون',
                            two: 'دواءان منخفضان المخزون',
                            few: 'أدوية منخفضة المخزون',
                            many: 'دواء منخفض المخزون'
                        })
                        : 'المخزون منتظم — لا توقعات حرجة';
                const forecastTile = document.getElementById('dashboard-quick-forecast');
                if (forecastTile) {
                    forecastTile.classList.toggle('is-red', forecastCritical > 0);
                    forecastTile.classList.toggle('is-green', forecastCritical === 0);
                }
            }
            const today = data.today || {};
            const month = data.month || {};
            const inv = data.inventory || {};
            const lowStockAlertsEnabled = !this.boot() || this.boot().enableLowStockAlerts !== false;
            const inventoryCards = [
                ['قيمة المخزون', this.money(inv.inventory_valuation), 'blue', 'inventory-value'],
                ['عدد المنتجات', this.num(inv.total_products), 'gray', 'products']
            ];
            if (lowStockAlertsEnabled) {
                inventoryCards.push(['أصناف منخفضة', this.num(inv.low_stock_count), 'amber', 'low-stock']);
            }
            inventoryCards.push(['أصناف نافدة', this.num(inv.out_of_stock_count), 'red', 'out-of-stock']);
            const groups = [
                {
                    title: 'مؤشرات اليوم',
                    cards: [
                        ['مبيعات اليوم', this.money(today.net_sales), 'blue', 'sales'],
                        ['فواتير اليوم', this.num(today.invoice_count), 'gray', 'invoices'],
                        ['تحصيل اليوم', this.money(today.collected_amount), 'green', 'collected'],
                        ['ديون اليوم', this.money(today.credit_debt_amount), 'amber', 'debts']
                    ]
                },
                {
                    title: 'أداء الشهر',
                    cards: [
                        ['مبيعات الشهر', this.money(month.net_sales), 'green', 'month-sales'],
                        ['ربح الشهر الإجمالي', this.money(month.gross_profit), 'amber', 'profit'],
                        ['مصروفات الشهر', this.money(month.operating_expenses), 'red', 'expenses'],
                        ['صافي ربح الشهر', this.money(month.net_profit), this.num(month.net_profit) >= 0 ? 'green' : 'red', 'net-profit']
                    ]
                },
                {
                    title: 'المخزون',
                    cards: inventoryCards
                }
            ];
            const statsEl = document.getElementById('dashboard-stats');
            if (statsEl) {
                statsEl.innerHTML = groups.map(function (group) {
                    return '<div class="pgx-kpi-block">'
                        + '<div class="pgx-kpi-head"><h2>' + group.title + '</h2><span>' + group.cards.length + ' مؤشرات</span></div>'
                        + '<div class="pgx-kpi-grid">'
                        + group.cards.map(function (s) {
                            return this.statCard(s[0], s[1], s[2], this.kpiIcon(s[3]));
                        }, this).join('')
                        + '</div></div>';
                }, this).join('');
            }

            const updatedEl = document.getElementById('dashboard-updated');
            if (updatedEl) {
                const now = this.state.clockMs ? new Date(this.state.clockMs) : new Date();
                try {
                    updatedEl.textContent = new Intl.DateTimeFormat('ar-LY', {
                        hour: '2-digit', minute: '2-digit',
                        timeZone: this.boot().timezone || 'Africa/Tripoli'
                    }).format(now);
                } catch (e) {
                    updatedEl.textContent = now.toLocaleTimeString();
                }
            }

            const sidebarLow = lowStockAlertsEnabled ? this.num(inv.low_stock_count) : 0;
            const sidebarOut = this.num(inv.out_of_stock_count);
            this.setSidebarBadge('sidebar-badge-inventory', sidebarLow + sidebarOut, sidebarOut > 0 ? 'red' : 'amber');

            const alerts = [];
            const lowStockCount = lowStockAlertsEnabled ? this.num(inv.low_stock_count) : 0;
            if (lowStockCount > 0) {
                alerts.push('<button type="button" class="pgx-alert is-amber" title="عرض الأصناف ذات المخزون المنخفض" onclick="LeviPOS.openInventoryLowTab()">'
                    + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 2.8 19a1.5 1.5 0 0 0 1.3 2.2h15.8A1.5 1.5 0 0 0 21.2 19Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>'
                    + '<span>' + this.arabicCount(lowStockCount, {
                        one: 'صنف واحد أقل من حد المخزون',
                        two: 'صنفان أقل من حد المخزون',
                        few: 'أصناف أقل من حد المخزون',
                        many: 'صنفًا أقل من حد المخزون'
                    }) + '</span>'
                    + '<span class="pgx-alert-arrow" aria-hidden="true">‹</span></button>');
            }
            const outOfStockCount = this.num(inv.out_of_stock_count);
            if (outOfStockCount > 0) {
                alerts.push('<button type="button" class="pgx-alert is-red" title="عرض الأصناف النافدة من المخزون" onclick="LeviPOS.openInventoryLowTab()">'
                    + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/></svg>'
                    + '<span>' + this.arabicCount(outOfStockCount, {
                        one: 'صنف واحد نافد من المخزون',
                        two: 'صنفان نافدان من المخزون',
                        few: 'أصناف نافدة من المخزون',
                        many: 'صنفًا نافدًا من المخزون'
                    }) + '</span>'
                    + '<span class="pgx-alert-arrow" aria-hidden="true">‹</span></button>');
            }
            if (this.num(expiryCounts.expired) > 0) {
                alerts.push('<button type="button" class="pgx-alert is-red" title="عرض الأدوية المنتهية" onclick="LeviPOS.navigate(\'expiry\')">'
                    + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>'
                    + '<span>' + this.arabicCount(this.num(expiryCounts.expired), {
                        one: 'دفعة دواء منتهية الصلاحية',
                        two: 'دفعتا دواء منتهيتا الصلاحية',
                        few: 'دفعات أدوية منتهية الصلاحية',
                        many: 'دفعة دواء منتهية الصلاحية'
                    }) + '</span>'
                    + '<span class="pgx-alert-arrow" aria-hidden="true">‹</span></button>');
            }
            if (this.num(expiryCounts.near_expiry) > 0) {
                alerts.push('<button type="button" class="pgx-alert is-amber" title="عرض الأدوية القريبة من الانتهاء" onclick="LeviPOS.navigate(\'expiry\')">'
                    + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>'
                    + '<span>' + this.arabicCount(this.num(expiryCounts.near_expiry), {
                        one: 'دفعة دواء قريبة من الانتهاء',
                        two: 'دفعتا دواء قريبتان من الانتهاء',
                        few: 'دفعات أدوية قريبة من الانتهاء',
                        many: 'دفعة دواء قريبة من الانتهاء'
                    }) + '</span>'
                    + '<span class="pgx-alert-arrow" aria-hidden="true">‹</span></button>');
            }
            this.setSidebarBadge('sidebar-badge-expiry', this.num(expiryCounts.expired) + this.num(expiryCounts.near_expiry), this.num(expiryCounts.expired) > 0 ? 'red' : 'amber');
            if (this.num(month.credit_debt_amount) > 0) {
                alerts.push('<button type="button" class="pgx-alert is-amber" title="عرض العملاء والديون" onclick="LeviPOS.openMonthDebts()">'
                    + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7h16a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/><path d="M3 7V6a2 2 0 0 1 2-2h11"/><circle cx="17" cy="13" r="1.3"/></svg>'
                    + '<span>ديون الشهر: ' + this.money(month.credit_debt_amount) + '</span>'
                    + '<span class="pgx-alert-arrow" aria-hidden="true">‹</span></button>');
            }
            const alertsEl = document.getElementById('dashboard-alerts');
            if (alertsEl) {
                alertsEl.innerHTML = alerts.length
                    ? alerts.join('')
                    : '<div class="pgx-alerts-empty">لا توجد تنبيهات حالية.</div>';
            }

            this.renderDashboardReadiness(data, expiryCounts);
            this.renderDashboardInteractions(pharma);
            this.renderDashboardHeatmap(pharma.hourly_sales || []);
            this.renderDashboardExpiryFocus(expiryData && expiryData.alerts ? expiryData.alerts : []);
            this.renderDashboardTimeline(data);
            this.renderDashboardCharts(data);
            this.renderDashboardRecent(data.recent_sales || []);
        },

        renderDashboardReadiness(data, expiryCounts) {
            const pharma = data.pharmacy || {};
            const inv = data.inventory || {};
            const rx = pharma.prescriptions || {};
            const expired = this.num(expiryCounts.expired);
            const near = this.num(expiryCounts.near_expiry);
            const low = this.num(inv.low_stock_count);
            const out = this.num(inv.out_of_stock_count);
            const pending = this.num(rx.pending);
            const interactions = this.num(pharma.interaction_alerts);
            const totalProducts = this.num(inv.total_products);
            const scores = [
                { label: 'الانتهاء', value: Math.max(0, 100 - expired * 12 - near * 4) },
                { label: 'المخزون', value: totalProducts > 0 ? Math.max(0, 100 - out * 8 - low * 3) : 100 },
                { label: 'الروشتات', value: Math.max(0, 100 - pending * 8) },
                { label: 'التداخلات', value: Math.max(0, 100 - interactions * 15) }
            ];
            const overall = Math.round(scores.reduce(function (sum, item) { return sum + item.value; }, 0) / scores.length);
            const tone = overall >= 80 ? 'var(--wc-green)' : overall >= 60 ? 'var(--wc-amber)' : 'var(--wc-red)';

            const heroRing = document.getElementById('dashboard-hero-readiness-ring');
            if (heroRing) {
                heroRing.style.background = 'conic-gradient(#ffffff 0 ' + overall + '%, rgba(255,255,255,.18) ' + overall + '% 100%)';
                const heroValue = heroRing.querySelector('b');
                if (heroValue) heroValue.textContent = overall + '%';
            }
            const tag = document.getElementById('dashboard-readiness-tag');
            if (tag) tag.textContent = overall >= 90 ? 'ممتاز' : overall >= 80 ? 'جيد جدًا' : overall >= 60 ? 'جيد' : 'يحتاج متابعة';

            const el = document.getElementById('dashboard-readiness-body');
            if (!el) return;
            const rows = scores.map(function (item) {
                return '<div class="pgx-readiness-metric"><span><b>' + this.escapeHtml(item.label) + '</b><b>' + item.value + '%</b></span>'
                    + '<i><b style="width:' + item.value + '%"></b></i></div>';
            }, this).join('');
            const lowest = scores.slice().sort(function (a, b) { return a.value - b.value; })[0];
            const note = overall >= 90
                ? 'كل المؤشرات داخل المعدل الطبيعي — صيدليتك جاهزة لخدمة المرضى.'
                : 'يحتاج «' + this.escapeHtml(lowest.label) + '» متابعة اليوم لرفع جاهزية الصيدلية.';
            el.innerHTML = '<div class="pgx-readiness">'
                + '<div class="pgx-readiness-ring" style="background:conic-gradient(' + tone + ' 0 ' + overall + '%, var(--wc-line) ' + overall + '% 100%)"><b>' + overall + '%</b></div>'
                + '<div class="pgx-readiness-metrics">' + rows + '</div>'
                + '</div>'
                + '<div class="pgx-readiness-note">' + note + '</div>';
        },

        renderDashboardInteractions(pharma) {
            const list = document.getElementById('dashboard-interaction-list');
            const tag = document.getElementById('dashboard-interaction-tag');
            const details = Array.isArray(pharma.interaction_details) ? pharma.interaction_details : [];
            if (tag) {
                tag.textContent = details.length
                    ? this.arabicCount(details.length, { one: 'تحذير واحد', two: 'تحذيران', few: 'تحذيرات', many: 'تحذيرًا' })
                    : 'لا توجد تحذيرات';
            }
            if (!list) return;
            if (!details.length) {
                list.innerHTML = '<div class="pgx-empty-soft">لا توجد تداخلات مسجلة بين أدوية الروشتات المعلّقة. سيظهر أي تحذير هنا فور رصد المادة الفعالة.</div>';
                return;
            }
            const canOpen = this.perm('prescriptions');
            list.innerHTML = '<div class="pgx-interaction-list">' + details.map(function (item) {
                const message = String(item.message || '').split(' source:')[0];
                const number = String(item.prescription_number || ('#' + item.id));
                const patient = String(item.patient_name || 'مريض');
                const severity = String(item.severity_label || 'متوسط');
                return '<div class="pgx-interaction">'
                    + '<div class="pgx-interaction-top"><span class="pgx-interaction-warn" aria-hidden="true">!</span><div>'
                    + '<strong>روشتة ' + this.escapeHtml(number) + ' — ' + this.escapeHtml(patient) + '</strong>'
                    + '<span class="pgx-interaction-sev">' + this.escapeHtml(severity) + '</span>'
                    + '</div></div>'
                    + '<p>' + this.escapeHtml(message) + '</p>'
                    + (canOpen
                        ? '<button type="button" onclick="LeviPOS.openPrescriptionModal(' + Number(item.id) + ')">فحص الروشتة ←</button>'
                        : '')
                    + '</div>';
            }, this).join('') + '</div>';
        },

        renderDashboardHeatmap(hours) {
            const body = document.getElementById('dashboard-heatmap-body');
            const peakTag = document.getElementById('dashboard-heatmap-peak');
            const rows = Array.isArray(hours) ? hours : [];
            if (!body) return;
            if (!rows.length) {
                if (peakTag) peakTag.textContent = '—';
                body.innerHTML = '<div class="pgx-empty-soft">لا توجد بيانات مبيعات بالساعة لعرضها اليوم.</div>';
                return;
            }
            const counts = rows.map(function (row) { return this.num(row.invoice_count); }, this);
            const max = Math.max.apply(null, counts.concat([1]));
            const colors = ['#f6ead9', '#f4dfc7', '#f1cda4', '#edbb84', '#e8a765', '#e2924d', '#d97706', '#c2410c'];
            let peak = rows[0];
            rows.forEach(function (row) {
                if (this.num(row.invoice_count) > this.num(peak.invoice_count)) peak = row;
            }, this);
            if (peakTag) {
                peakTag.textContent = this.num(peak.invoice_count) > 0
                    ? 'الذروة ' + Number(peak.hour) + ':00'
                    : 'لا توجد مبيعات اليوم';
            }
            const cells = rows.map(function (row) {
                const count = this.num(row.invoice_count);
                const level = count <= 0 ? 0 : Math.min(7, Math.max(1, Math.ceil((count / max) * 7)));
                const title = Number(row.hour) + ':00 — ' + this.arabicCount(count, {
                    one: 'فاتورة واحدة', two: 'فاتورتان', few: 'فواتير', many: 'فاتورة'
                }) + ' · ' + this.money(row.total_amount);
                return '<i style="background:' + colors[level] + '" title="' + this.escapeHtml(title) + '"></i>';
            }, this).join('');
            body.innerHTML = '<div class="pgx-heat" role="img" aria-label="كثافة المبيعات من الثامنة صباحًا حتى الثامنة مساءً">' + cells + '</div>'
                + '<div class="pgx-heat-labels"><span>08</span><span>10</span><span>12</span><span>14</span><span>16</span><span>18</span><span>20</span></div>'
                + '<div class="pgx-heat-legend">أقل <b style="background:' + colors[0] + '"></b><b style="background:' + colors[3] + '"></b><b style="background:' + colors[7] + '"></b> أعلى</div>';
        },

        renderDashboardExpiryFocus(alerts) {
            const el = document.getElementById('dashboard-expiry-focus');
            if (!el) return;
            const countEl = document.getElementById('dashboard-focus-count');
            const total = alerts.length;
            if (countEl) countEl.textContent = total
                ? this.arabicCount(total, { one: 'دفعة واحدة', two: 'دفعتان', few: 'دفعات', many: 'دفعة' })
                : 'لا توجد تنبيهات';
            const rows = alerts.slice(0, 5).map(function (a) {
                const days = this.num(a.days_left);
                const expired = days < 0;
                const urgent = expired || days <= 7;
                const width = expired ? 100 : days <= 7 ? 92 : days <= 14 ? 74 : 55;
                const label = expired
                    ? 'منتهية'
                    : this.arabicCount(days, { one: 'يوم واحد', two: 'يومان', few: 'أيام', many: 'يوم' }) + ' متبقية';
                return '<div class="pgx-focus-row">'
                    + '<div class="pgx-focus-top">'
                    + '<span class="pgx-focus-name">' + this.escapeHtml(String(a.product_name || '')) + '</span>'
                    + '<span class="pgx-focus-badge ' + (expired ? 'is-red' : urgent ? 'is-amber' : 'is-green') + '">' + label + '</span>'
                    + '</div>'
                    + '<div class="pgx-focus-meta">'
                    + '<span>الدفعة ' + this.escapeHtml(String(a.batch_number || '')) + '</span>'
                    + '<span>' + this.num(a.quantity) + ' وحدة</span>'
                    + '</div>'
                    + '<div class="pgx-focus-track"><i class="' + (expired ? 'is-red' : urgent ? 'is-amber' : 'is-green') + '" style="width:' + width + '%"></i></div>'
                    + '</div>';
            }, this).join('');
            el.innerHTML = rows
                ? rows + '<button type="button" class="pgx-focus-link" onclick="LeviPOS.navigate(\'expiry\')">عرض كل الدفعات ←</button>'
                : '<div class="pgx-empty-soft">لا توجد دفعات قريبة من الانتهاء حاليًا.</div>';
        },

        renderDashboardTimeline(data) {
            const el = document.getElementById('dashboard-timeline');
            if (!el) return;
            const pharma = data.pharmacy || {};
            const rx = pharma.prescriptions || {};
            const expiry = pharma.expiry || {};
            const purchases = data.recent_purchases || [];
            const sales = data.recent_sales || [];
            const timeOf = function (value) {
                const text = String(value || '');
                return text.length >= 16 ? text.substring(11, 16) : '—';
            };
            const events = [];
            if (purchases.length) {
                events.push({
                    time: timeOf(purchases[0].purchase_date),
                    title: 'استلام توريد جديد',
                    detail: String(purchases[0].supplier_name || 'مورد') + ' — فاتورة ' + String(purchases[0].invoice_number || ''),
                    tone: 'green'
                });
            }
            if (this.num(rx.pending) > 0) {
                events.push({
                    time: 'الآن',
                    title: this.arabicCount(this.num(rx.pending), {
                        one: 'روشتة بانتظار المراجعة',
                        two: 'روشتتان بانتظار المراجعة',
                        few: 'روشتات بانتظار المراجعة',
                        many: 'روشتة بانتظار المراجعة'
                    }),
                    detail: 'مراجعة الصيدلي مطلوبة قبل الصرف',
                    tone: 'amber'
                });
            }
            if (this.num(expiry.expired) + this.num(expiry.near_expiry) > 0) {
                events.push({
                    time: 'اليوم',
                    title: this.arabicCount(this.num(expiry.expired) + this.num(expiry.near_expiry), {
                        one: 'دفعة دواء تحتاج انتباهًا',
                        two: 'دفعتا دواء تحتاجان انتباهًا',
                        few: 'دفعات أدوية تحتاج انتباهًا',
                        many: 'دفعة دواء تحتاج انتباهًا'
                    }),
                    detail: 'الصرف يبدأ من الدفعة الأقرب للانتهاء (FEFO)',
                    tone: 'red'
                });
            }
            if (sales.length) {
                events.push({
                    time: timeOf(sales[0].sale_date),
                    title: 'أحدث فاتورة صُرفت',
                    detail: String(sales[0].invoice_number || '') + ' — ' + this.money(sales[0].total_amount),
                    tone: 'green'
                });
            }
            if (this.num((pharma.patients || {}).new_today) > 0) {
                events.push({
                    time: 'اليوم',
                    title: this.arabicCount(this.num(pharma.patients.new_today), {
                        one: 'مريض جديد',
                        two: 'مريضان جديدان',
                        few: 'مرضى جدد',
                        many: 'مريض جديد'
                    }),
                    detail: 'سُجّل ملفه في الصيدلية',
                    tone: 'amber'
                });
            }
            el.innerHTML = events.length
                ? '<div class="pgx-timeline">' + events.slice(0, 5).map(function (e) {
                    return '<div class="pgx-tl-item is-' + e.tone + '">'
                        + '<span class="pgx-tl-time">' + this.escapeHtml(e.time) + '</span>'
                        + '<div><strong>' + this.escapeHtml(e.title) + '</strong>'
                        + '<small>' + this.escapeHtml(e.detail) + '</small></div>'
                        + '</div>';
                }, this).join('') + '</div>'
                : '<div class="pgx-empty-soft">لا توجد نشاطات مسجلة اليوم بعد.</div>';
        },

        renderDashboardCharts(data) {
            this.renderTopProductsChart(data.top_products || []);
            this.renderCategorySalesChart(data.sales_by_category || []);
        },

        renderTopProductsChart(items) {
            const el = document.getElementById('chart-top-products');
            if (!el) return;
            const top = items.slice(0, 6);
            if (!top.length) {
                el.innerHTML = '<div class="chart-empty">لا توجد مبيعات مسجلة لعرضها في هذه الفترة.</div>';
                return;
            }
            const values = top.map(function (p) { return Math.max(0, this.num(p.net_qty_sold)); }, this);
            const max = Math.max.apply(null, values.concat([1]));
            el.innerHTML = top.map(function (p, i) {
                const qty = values[i];
                const width = Math.max(3, Math.round((qty / max) * 100));
                const name = String(p.product_name || '');
                const safeName = this.escapeHtml(name);
                return '<div class="mini-bar-row" title="' + safeName + ' — ' + this.num(qty) + '">'
                    + '<div class="mini-bar-head">'
                    + '<span class="mini-bar-rank">' + (i + 1) + '</span>'
                    + '<span class="mini-bar-name">' + safeName + '</span>'
                    + '<span class="mini-bar-qty">' + this.num(qty) + '</span>'
                    + '</div>'
                    + '<div class="mini-bar-track"><span class="mini-bar-fill" style="width:' + width + '%"></span></div>'
                    + '</div>';
            }, this).join('');
        },

        renderCategorySalesChart(items) {
            const el = document.getElementById('chart-category-sales');
            if (!el) return;
            const colors = [
                'var(--levi-primary, #4c7396)',
                'var(--levi-success, #49836b)',
                'var(--levi-warning, #a87b2c)',
                'var(--levi-danger, #ac5a52)',
                'var(--levi-violet, #6d7099)',
                'var(--levi-rose, #96606f)'
            ];
            const positive = items.filter(function (c) { return this.num(c.total_sales) > 0; }, this)
                .sort(function (a, b) { return this.num(b.total_sales) - this.num(a.total_sales); }.bind(this));
            if (!positive.length) {
                el.innerHTML = '<div class="chart-empty">لا توجد مبيعات مسجلة لعرضها في هذه الفترة.</div>';
                return;
            }
            const top = positive.slice(0, 5);
            const restTotal = positive.slice(5).reduce(function (sum, c) { return sum + this.num(c.total_sales); }.bind(this), 0);
            if (restTotal > 0) top.push({ category_name: 'تصنيفات أخرى', total_sales: restTotal });
            const total = top.reduce(function (sum, c) { return sum + this.num(c.total_sales); }.bind(this), 0);
            let cursor = 0;
            const segments = [];
            const rows = [];
            top.forEach(function (c, i) {
                const value = this.num(c.total_sales);
                const pct = total > 0 ? (value / total) * 100 : 0;
                const start = cursor;
                cursor += pct;
                const color = colors[i % colors.length];
                segments.push(color + ' ' + start.toFixed(2) + '% ' + cursor.toFixed(2) + '%');
                const name = String(c.category_name || 'بدون تصنيف');
                const safeName = this.escapeHtml(name);
                rows.push('<div class="cat-legend-row" title="' + safeName + ' — ' + this.money(value) + '">'
                    + '<span class="cat-dot" style="background:' + color + '"></span>'
                    + '<span class="cat-name">' + safeName + '</span>'
                    + '<span class="cat-pct">' + pct.toFixed(1) + '%</span>'
                    + '</div>');
            }, this);
            const totalText = this.num(total).toLocaleString('en-US', { maximumFractionDigits: 0 });
            el.innerHTML = '<div class="cat-donut" style="background: conic-gradient(' + segments.join(', ') + ');" aria-hidden="true">'
                + '<div class="cat-donut-hole">'
                + '<span class="cat-donut-total">' + totalText + '</span>'
                + '<span class="cat-donut-label">إجمالي المبيعات</span>'
                + '</div></div>'
                + '<div class="cat-legend">' + rows.join('') + '</div>';
        },

        renderDashboardRecent(sales) {
            const currency = this.boot().baseCurrency || 'LYD';
            const moneyCell = function (r) {
                const text = this.money(r.total_amount);
                let inner = this.escapeHtml(text);
                if (text.slice(-(currency.length + 1)) === ' ' + currency) {
                    inner = this.escapeHtml(text.slice(0, -(currency.length + 1)).trim())
                        + ' <small>' + this.escapeHtml(currency) + '</small>';
                }
                return '<span class="pgx-money">' + inner + '</span>';
            };
            this.renderTable('dashboard-recent-sales', [
                { label: 'الفاتورة', render: function (r) {
                    return '<span class="pgx-inv-no"><i aria-hidden="true"></i>' + this.escapeHtml(r.invoice_number) + '</span>';
                }.bind(this) },
                { label: 'التاريخ', render: function (r) {
                    return '<span class="pgx-inv-date">' + this.dateTime(r.sale_date) + '</span>';
                }.bind(this) },
                { label: 'الإجمالي', render: moneyCell.bind(this) },
                { label: 'الحالة', render: function (r) {
                    const status = r.payment_status || r.status;
                    return '<span class="pgx-status is-' + this.statusTone(status) + '">'
                        + this.escapeHtml(this.statusLabel(status)) + '</span>';
                }.bind(this) },
                { label: '', render: function (r) {
                    return '<button type="button" class="pgx-row-btn" onclick="LeviPOS.openInvoiceDetails(' + r.id + ')">'
                        + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="2.5"/></svg>'
                        + 'عرض</button>';
                }.bind(this) }
            ], sales, 'لا توجد فواتير حديثة.');

            const countEl = document.getElementById('dashboard-recent-count');
            if (countEl) {
                countEl.textContent = sales.length
                    ? this.arabicCount(sales.length, {
                        one: 'فاتورة واحدة', two: 'فاتورتان', few: 'فواتير', many: 'فاتورة'
                    })
                    : 'لا فواتير';
            }
        },

        /* =================================================================
         * POS
         * ================================================================= */
        async loadPos() {
            await this.loadPosMeta();
            await this.searchPosProducts();
            this.renderCart();
            this.refreshSessionBadge();
            this.focusPosSearch();
        },

        async loadPosMeta() {
            if (this.state.posMetaLoaded) return;
            const results = await Promise.all([
                this.apiGet('pos.categories'),
                this.apiGet('pos.brands'),
                this.apiGet('pos.session_status'),
                this.apiGet('prescriptions.list', { status: 'issued' })
            ]);
            if (results[0]) this.state.categories = results[0].categories || [];
            if (results[1]) this.state.brands = results[1].brands || [];
            if (results[2]) {
                this.state.session = results[2].my_session || results[2].session || null;
                this.state.registers = results[2].registers || [];
            }
            if (results[3]) this.state.openPrescriptions = results[3].prescriptions || [];
            const catFilter = document.getElementById('pos-category-filter');
            if (catFilter) {
                catFilter.innerHTML = this.options(this.state.categories, 'id', 'name', '', 'كل التصنيفات');
            }
            const brandFilter = document.getElementById('pos-brand-filter');
            if (brandFilter) {
                brandFilter.innerHTML = this.options(this.state.brands, 'id', 'name', '', 'كل الشركات');
            }
            const prescription = document.getElementById('pos-prescription');
            if (prescription) {
                prescription.innerHTML = '<option value="">بدون روشتة</option>' + (this.state.openPrescriptions || []).map(function (p) {
                    return '<option value="' + Number(p.id) + '">' + this.escapeHtml(p.prescription_number || '')
                        + ' — ' + this.escapeHtml(p.patient_name || '') + '</option>';
                }, this).join('');
            }
            this.state.posMetaLoaded = true;
            this.renderPosCategoryPills();
            this.syncPosPaymentButtons();
            this.syncPosCurrencyLabel();
        },

        bindPosEvents() {
            const self = this;
            const search = document.getElementById('pos-search-input');
            if (search) {
                search.addEventListener('input', function () {
                    self.debounce('posSearch', function () { self.searchPosProducts(); }, 220);
                });
                search.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        if (event.repeat) return;
                        const value = search.value.trim();
                        if (!value) {
                            self.searchPosProducts();
                            return;
                        }
                        self.submitPosBarcode(value);
                    } else if (event.key === 'Escape') {
                        search.value = '';
                        self.state.catalog = [];
                        self.searchPosProducts();
                        event.preventDefault();
                    }
                });
            }
            ['pos-category-filter', 'pos-brand-filter'].forEach(function (id) {
                const el = document.getElementById(id);
                if (el) el.addEventListener('change', function () {
                    if (id === 'pos-category-filter') self.renderPosCategoryPills();
                    self.searchPosProducts();
                });
            });
            const payMethods = document.getElementById('posx-pay-methods');
            if (payMethods) {
                payMethods.addEventListener('click', function (event) {
                    const btn = event.target.closest('[data-posx-pay]');
                    if (!btn) return;
                    self.setPosPaymentMethod(btn.getAttribute('data-posx-pay'));
                });
            }
            const categoryPills = document.getElementById('posx-category-pills');
            if (categoryPills) {
                categoryPills.addEventListener('click', function (event) {
                    const btn = event.target.closest('[data-posx-cat]');
                    if (!btn) return;
                    const filter = document.getElementById('pos-category-filter');
                    if (!filter) return;
                    filter.value = btn.getAttribute('data-posx-cat');
                    self.renderPosCategoryPills();
                    self.searchPosProducts();
                });
            }
            const quickCash = document.querySelector('.posx-quick');
            if (quickCash) {
                quickCash.addEventListener('click', function (event) {
                    const btn = event.target.closest('[data-posx-cash]');
                    if (!btn) return;
                    self.posQuickCash(btn.getAttribute('data-posx-cash'));
                });
            }
            const keypad = document.getElementById('posx-keypad');
            if (keypad) {
                keypad.addEventListener('click', function (event) {
                    const btn = event.target.closest('[data-posx-key]');
                    if (!btn) return;
                    self.posKeypadKey(btn.getAttribute('data-posx-key'));
                });
            }
            const customer = document.getElementById('pos-customer-search');
            if (customer) {
                customer.addEventListener('input', function () {
                    self.debounce('posCustomer', function () { self.posCustomerSearch(); }, 250);
                });
                customer.addEventListener('change', function () { self.selectPosCustomer(); });
                customer.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        const results = self.state.customerResults || [];
                        if (results.length) self.choosePosCustomer(results[0]);
                    }
                });
                customer.addEventListener('focus', function () {
                    if ((self.state.customerResults || []).length) self.posCustomerSearch();
                });
            }
            document.addEventListener('click', function (event) {
                if (event.target && !event.target.closest('.pos-customer-box')) {
                    self.hidePosCustomerSuggest();
                }
            });
            const discount = document.getElementById('pos-invoice-discount');
            if (discount) discount.addEventListener('input', function () { self.updatePaymentCalculations(); });
            const method = document.getElementById('pos-payment-method');
            if (method) method.addEventListener('change', function () { self.onPosPaymentMethodChange(); });
            const prescription = document.getElementById('pos-prescription');
            if (prescription) prescription.addEventListener('change', function () {
                self.updatePaymentCalculations();
            });
            const paid = document.getElementById('pos-paid-amount');
            if (paid) paid.addEventListener('input', function () { self.updatePaymentCalculations(); });
        },

        /* =================================================================
         * Barcode scanner support (POS)
         * A hardware scanner behaves like a keyboard: it types the barcode
         * very fast then sends Enter. We listen for Enter in the search box
         * and for scanner-like input anywhere else on the POS screen, then
         * add the matching product to the cart immediately.
         * ================================================================= */
        bindPosBarcodeScanner() {
            const self = this;
            let buffer = '';
            let lastKeyAt = 0;
            const reset = function () { buffer = ''; lastKeyAt = 0; };

            document.addEventListener('keydown', function (event) {
                const posSection = document.getElementById('view-pos');
                if (!posSection || !posSection.classList.contains('active')) {
                    reset();
                    return;
                }
                const target = event.target;
                const tag = target && target.tagName ? String(target.tagName).toLowerCase() : '';
                const inField = tag === 'input' || tag === 'textarea' || tag === 'select'
                    || (target && target.isContentEditable);
                if (inField) {
                    // The search field has its own Enter handler.
                    reset();
                    return;
                }
                if (event.ctrlKey || event.altKey || event.metaKey) {
                    reset();
                    return;
                }
                if (event.key === 'Escape') {
                    reset();
                    return;
                }
                if (event.key === 'Enter') {
                    if (event.repeat) return;
                    if (buffer.length >= 3) {
                        event.preventDefault();
                        const code = buffer;
                        reset();
                        self.submitPosBarcode(code);
                    } else {
                        reset();
                    }
                    return;
                }
                if (event.key && event.key.length === 1) {
                    const now = Date.now();
                    if (lastKeyAt && now - lastKeyAt > 350) buffer = '';
                    buffer += event.key;
                    lastKeyAt = now;
                }
            });
        },

        focusPosSearch() {
            const input = document.getElementById('pos-search-input');
            if (!input) return;
            const posSection = document.getElementById('view-pos');
            if (posSection && !posSection.classList.contains('active')) return;
            try {
                input.focus({ preventScroll: true });
                input.select();
            } catch (e) {
                input.focus();
            }
        },

        async submitPosBarcode(rawCode) {
            const value = String(rawCode || '').trim();
            const search = document.getElementById('pos-search-input');
            if (!value) {
                if (search) search.value = '';
                return;
            }
            const overlay = document.getElementById('modal-overlay');
            if (overlay && overlay.classList.contains('open')) {
                // Do not add items while a dialog is open.
                return;
            }
            clearTimeout(this.state.timers['posSearch']);
            try {
                const data = await this.apiGet('pos.search_products', { q: value, limit: 20 });
                if (!data) return;
                const items = data.items || [];
                const needle = value.toLowerCase();
                let match = null;
                for (let i = 0; i < items.length; i++) {
                    const barcode = String(items[i].barcode || '').trim().toLowerCase();
                    const sku = String(items[i].sku || '').trim().toLowerCase();
                    if (barcode === needle || sku === needle) {
                        match = items[i];
                        break;
                    }
                }
                if (!match && items.length === 1) match = items[0];

                if (match) {
                    const label = (match.product_name || match.sku || '')
                        + (match.variant_name ? ' - ' + match.variant_name : '');
                    const added = this.addToCart(match);
                    if (added) {
                        this.toast('تمت إضافة «' + label + '» إلى السلة.', 'success');
                        if (search) search.value = '';
                        this.searchPosProducts();
                    }
                } else {
                    this.state.catalog = items;
                    this.renderPosCatalog();
                    this.toast('لا يوجد منتج مطابق للباركود: ' + value, 'warning');
                }
            } finally {
                this.focusPosSearch();
            }
        },

        bindLiveSearch() {
            const self = this;
            const fields = [
                { id: 'products-search', method: 'loadProducts', delay: 250 },
                { id: 'inventory-search', method: 'loadInventory', delay: 250 },
                { id: 'suppliers-search', method: 'loadSuppliers', delay: 250 },
                { id: 'customers-search', method: 'loadCustomers', delay: 250 },
                { id: 'invoices-q', method: 'loadInvoices', delay: 300 },
                { id: 'audit-q', method: 'loadAuditLogs', delay: 300 },
                { id: 'batches-search', method: 'loadBatches', delay: 250 },
                { id: 'doctors-search', method: 'loadDoctors', delay: 250 },
                { id: 'prescriptions-search', method: 'loadPrescriptions', delay: 250 }
            ];
            fields.forEach(function (field) {
                const el = document.getElementById(field.id);
                if (!el) return;
                const run = function () {
                    if (typeof self[field.method] === 'function') self[field.method]();
                };
                const timerKey = 'liveSearch:' + field.id;
                el.addEventListener('input', function () {
                    self.debounce(timerKey, run, field.delay);
                });
                el.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        run();
                    }
                });
            });

            const returnSearch = document.getElementById('return-invoice-search');
            if (returnSearch) {
                const runReturn = function () {
                    if (returnSearch.value.trim().length < 3) return;
                    self.liveSearchReturnInvoice();
                };
                returnSearch.addEventListener('input', function () {
                    self.debounce('liveSearch:return-invoice-search', runReturn, 450);
                });
                returnSearch.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        self.searchReturnInvoice(false);
                    }
                });
            }
        },

        async searchPosProducts() {
            const qEl = document.getElementById('pos-search-input');
            const catEl = document.getElementById('pos-category-filter');
            const brandEl = document.getElementById('pos-brand-filter');
            const q = qEl ? qEl.value.trim() : '';
            const categoryId = catEl ? catEl.value : '';
            const brandId = brandEl ? brandEl.value : '';
            const data = await this.apiGet('pos.search_products', {
                q: q, category_id: categoryId, brand_id: brandId, limit: 80
            });
            if (!data) return;
            this.state.catalog = data.items || [];
            this.renderPosCatalog();
        },

        posProductEmoji(product) {
            const category = String((product && product.category_name) || '');
            if (/مضاد|حيوي|بكتير|antibi/i.test(category)) return '🧪';
            if (/فيتامين|مكمّل|مكمل|vitamin/i.test(category)) return '🌿';
            if (/شراب|أطفال|طبيف|syrup/i.test(category)) return '🍼';
            return '💊';
        },

        renderPosCatalog() {
            const grid = document.getElementById('pos-catalog-grid');
            if (!grid) return;
            const items = this.state.catalog || [];
            const countEl = document.getElementById('pos-catalog-count');
            if (countEl) countEl.textContent = String(items.length);
            if (!items.length) {
                grid.innerHTML = '<div class="empty-state">لا توجد منتجات مطابقة للبحث.</div>';
                return;
            }
            grid.innerHTML = items.map(function (p, index) {
                const stock = this.num(p.stock_qty);
                const out = stock <= 0 && !this.boot().allowNegativeStock;
                const low = !out && stock <= Math.max(this.num(p.min_stock_level), 5);
                const meta = [p.sku, p.category_name].filter(function (v) { return !!v; }).join(' • ');
                const label = this.productLabel(p);
                return '<div class="posx-tile' + (out ? ' is-out' : '') + '" onclick="LeviPOS.addToCartByIndex(' + index + ')" title="' + this.escapeHtml(label) + '">'
                    + '<div class="posx-tile-top">'
                    + '<span class="posx-tile-emoji" aria-hidden="true">' + this.posProductEmoji(p) + '</span>'
                    + '<span class="posx-name">' + this.escapeHtml(p.product_name || '') + (p.variant_name ? ' - ' + this.escapeHtml(p.variant_name) : '') + '</span>'
                    + '</div>'
                    + '<span class="posx-meta">' + this.escapeHtml(meta) + '</span>'
                    + '<div class="posx-tile-bottom">'
                    + '<span class="posx-price">' + this.money(p.selling_price) + '</span>'
                    + '<span class="posx-stock' + (out ? ' is-out' : (low ? ' is-low' : '')) + '">' + (out ? 'نفد' : stock) + '</span>'
                    + '</div>'
                    + '</div>';
            }, this).join('');
        },

        addToCartByIndex(index) {
            const product = this.state.catalog[index];
            if (product && this.addToCart(product)) this.focusPosSearch();
        },

        addToCart(product) {
            const key = this.productKey(product);
            const stock = this.num(product.stock_qty);
            const allowNegative = !!this.boot().allowNegativeStock;
            const existing = this.state.cart.find(function (item) { return item.key === key; });
            if (existing) {
                if (!allowNegative && existing.quantity + 1 > stock) {
                    this.toast('الكمية المتوفرة في المخزون: ' + stock, 'warning');
                    return false;
                }
                existing.quantity += 1;
            } else {
                if (!allowNegative && stock <= 0) {
                    this.toast('هذا المنتج غير متوفر في المخزون.', 'warning');
                    return false;
                }
                this.state.cart.push({
                    key: key,
                    product_id: product.product_id,
                    variant_id: product.variant_id || null,
                    name: (product.product_name || '') + (product.variant_name ? ' - ' + product.variant_name : ''),
                    sku: product.sku || '',
                    unit_price: this.num(product.selling_price),
                    quantity: 1,
                    discount_amount: 0,
                    max_stock: stock,
                    batch_number: product.batch_number || '',
                    expiry_date: product.expiry_date || '',
                    requires_prescription: !!product.requires_prescription,
                    purchase_cost: this.num(product.purchase_cost)
                });
            }
            this.renderCart();
            return true;
        },

        renderCart() {
            const list = document.getElementById('pos-cart-items');
            const cart = this.state.cart || [];
            const countEl = document.getElementById('pos-cart-count');
            if (countEl) countEl.textContent = String(cart.length);
            if (list) {
                if (!cart.length) {
                    list.innerHTML = '<div class="posx-cart-empty">'
                        + '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="20" r="1.6"></circle><circle cx="18" cy="20" r="1.6"></circle><path d="M2 3h2.2l2.3 11.2a2 2 0 0 0 2 1.6h8.6a2 2 0 0 0 2-1.6L21 7H6"></path></svg>'
                        + '<strong>السلة فارغة</strong>'
                        + '<span>امسح الباركود أو اختر منتجًا من القائمة لإضافته.</span>'
                        + '</div>';
                } else {
                    list.innerHTML = cart.map(function (item, index) {
                        const lineTotal = Math.max(0, item.unit_price * item.quantity - item.discount_amount);
                        const sku = item.sku ? this.escapeHtml(item.sku) : '';
                        return '<div class="posx-line">'
                            + '<div class="posx-line-main">'
                            + '<span class="posx-line-name" title="' + this.escapeHtml(item.name) + '">' + this.escapeHtml(item.name) + '</span>'
                            + '<span class="posx-line-meta">'
                            + (sku ? '<span>' + sku + '</span><span>•</span>' : '')
                            + '<span class="posx-unit">' + this.money(item.unit_price) + '</span>'
                            + '</span>'
                            + ((item.batch_number || item.expiry_date)
                                ? '<div class="posx-line-serial"><span class="posx-batch-badge">' + (item.batch_number ? 'دفعة: ' + this.escapeHtml(item.batch_number) : '')
                                    + (item.expiry_date ? (item.batch_number ? ' • ' : '') + 'انتهاء: ' + this.escapeHtml(item.expiry_date) : '') + '</span></div>'
                                : '')
                            + '<div class="posx-line-foot">'
                            + '<span class="posx-line-disc">خصم <input type="number" step="0.5" min="0" value="' + item.discount_amount + '" onchange="LeviPOS.updateCartItemDiscount(' + index + ', this.value)"></span>'
                            + '<span class="posx-stepper">'
                            + '<button type="button" onclick="LeviPOS.updateCartQty(' + index + ',-1)">−</button>'
                            + '<span>' + item.quantity + '</span>'
                            + '<button type="button" onclick="LeviPOS.updateCartQty(' + index + ',1)">+</button>'
                            + '</span>'
                            + '<span class="posx-line-total">' + this.money(lineTotal) + '</span>'
                            + '</div>'
                            + '</div>'
                            + '<button type="button" class="posx-remove" title="حذف الصنف" onclick="LeviPOS.removeFromCart(' + index + ')">×</button>'
                            + '</div>';
                    }, this).join('');
                }
            }
            const subtotal = this.cartSubtotal();
            const itemDiscount = this.cartItemDiscountTotal();
            const invoiceDiscount = this.cartInvoiceDiscount();
            const total = this.cartTotal();
            const taxAmount = this.cartTaxAmount();
            const subtotalEl = document.getElementById('pos-summary-subtotal');
            const discountEl = document.getElementById('pos-summary-discount');
            const totalEl = document.getElementById('pos-summary-total');
            const taxRowEl = document.getElementById('pos-summary-tax-row');
            const taxEl = document.getElementById('pos-summary-tax');
            const taxLabelEl = document.getElementById('pos-summary-tax-label');
            if (subtotalEl) subtotalEl.textContent = this.money(subtotal);
            if (discountEl) discountEl.textContent = this.money(itemDiscount + invoiceDiscount);
            if (totalEl) totalEl.textContent = this.money(total);
            if (taxRowEl) taxRowEl.style.display = taxAmount > 0 ? '' : 'none';
            if (taxEl) taxEl.textContent = this.money(taxAmount);
            if (taxLabelEl && taxAmount > 0) {
                taxLabelEl.textContent = 'الضريبة ' + this.num(this.boot().taxRate) + '%' + (this.boot().taxInclusive ? ' (مشمولة)' : '');
            }
            const paidInput = document.getElementById('pos-paid-amount');
            if (paidInput) {
                const current = this.num(paidInput.value);
                const method = document.getElementById('pos-payment-method');
                if (method && (method.value === 'card' || method.value === 'transfer')) {
                    paidInput.value = total.toFixed(2);
                } else if (current === 0 || Math.abs(current - this.state.lastTotal) < 0.001) {
                    paidInput.value = total.toFixed(2);
                }
            }
            this.state.lastTotal = total;
            this.updatePaymentCalculations();
        },

        updateCartQty(index, delta) {
            const item = this.state.cart[index];
            if (!item) return;
            const next = item.quantity + delta;
            if (next <= 0) { this.removeFromCart(index); return; }
            if (!this.boot().allowNegativeStock && next > item.max_stock) {
                this.toast('الكمية المتوفرة في المخزون: ' + item.max_stock, 'warning');
                return;
            }
            item.quantity = next;
            this.renderCart();
        },

        updateCartItemDiscount(index, value) {
            const item = this.state.cart[index];
            if (!item) return;
            const max = item.unit_price * item.quantity;
            item.discount_amount = Math.min(Math.max(this.num(value), 0), max);
            this.renderCart();
        },

        updateCartSerial(index, value) {
            const item = this.state.cart[index];
            if (!item) return;
            item.serial_or_imei = String(value || '').trim().slice(0, 100);
        },

        removeFromCart(index) {
            this.state.cart.splice(index, 1);
            this.renderCart();
        },

        clearCart() {
            if (!this.state.cart.length) return;
            if (!window.confirm('هل تريد مسح كل أصناف السلة؟')) return;
            this.state.cart = [];
            this.state.heldSaleId = null;
            this.renderCart();
        },

        cartSubtotal() {
            return (this.state.cart || []).reduce(function (sum, item) {
                return sum + item.unit_price * item.quantity;
            }, 0);
        },

        cartItemDiscountTotal() {
            return (this.state.cart || []).reduce(function (sum, item) {
                return sum + item.discount_amount;
            }, 0);
        },

        cartInvoiceDiscount() {
            const el = document.getElementById('pos-invoice-discount');
            const value = el ? this.num(el.value) : 0;
            const max = Math.max(0, this.cartSubtotal() - this.cartItemDiscountTotal());
            return Math.min(Math.max(value, 0), max);
        },

        cartTotal() {
            const net = this.cartNetTotal();
            const boot = this.boot();
            if (boot.taxEnabled && this.num(boot.taxRate) > 0 && !boot.taxInclusive) {
                return net + this.cartTaxAmount();
            }
            return net;
        },

        cartNetTotal() {
            return Math.max(0, this.cartSubtotal() - this.cartItemDiscountTotal() - this.cartInvoiceDiscount());
        },

        cartTaxAmount() {
            const boot = this.boot();
            const rate = this.num(boot.taxRate);
            if (!boot.taxEnabled || rate <= 0) return 0;
            const net = this.cartNetTotal();
            const amount = boot.taxInclusive ? net * rate / (100 + rate) : net * rate / 100;
            return Math.round(amount * 1000) / 1000;
        },

        onPosPaymentMethodChange() {
            const method = document.getElementById('pos-payment-method');
            const simple = document.getElementById('pos-simple-payment-wrap');
            const split = document.getElementById('pos-split-payment-wrap');
            const paid = document.getElementById('pos-paid-amount');
            if (!method) return;
            const value = method.value;
            if (simple) simple.style.display = value === 'split' || value === 'credit' ? 'none' : 'block';
            if (split) split.style.display = value === 'split' ? 'block' : 'none';
            if (value === 'split' && !document.querySelectorAll('#pos-split-rows .pos-split-row').length) {
                this.addSplitRow('cash', this.boot().baseCurrency || 'LYD', '');
            }
            if (value === 'credit' && paid) paid.value = '0';
            if ((value === 'card' || value === 'transfer') && paid) paid.value = this.cartTotal().toFixed(2);
            this.syncPosPaymentButtons();
            this.syncPosCurrencyLabel();
            this.updatePaymentCalculations();
        },

        setPosPaymentMethod(value) {
            const method = document.getElementById('pos-payment-method');
            if (!method) return;
            method.value = value;
            this.onPosPaymentMethodChange();
        },

        syncPosPaymentButtons() {
            const method = document.getElementById('pos-payment-method');
            const value = method ? method.value : 'cash';
            document.querySelectorAll('[data-posx-pay]').forEach(function (btn) {
                const active = btn.getAttribute('data-posx-pay') === value;
                btn.classList.toggle('is-active', active);
                btn.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        },

        syncPosCurrencyLabel() {
            const label = document.getElementById('posx-amount-cur');
            if (label) label.textContent = this.boot().baseCurrency || 'LYD';
        },

        renderPosCategoryPills() {
            const wrap = document.getElementById('posx-category-pills');
            if (!wrap) return;
            const filter = document.getElementById('pos-category-filter');
            const current = filter ? String(filter.value || '') : '';
            const cats = (this.state.categories || []).slice();
            const all = [{ id: '', name: 'كل المنتجات' }].concat(cats);
            wrap.innerHTML = all.map(function (c) {
                const id = String(c.id === null || c.id === undefined ? '' : c.id);
                return '<button type="button" class="posx-pill' + (id === current ? ' is-active' : '') + '" data-posx-cat="' + this.escapeHtml(id) + '">'
                    + this.escapeHtml(c.name || '')
                    + '</button>';
            }, this).join('');
        },

        posQuickCash(kind) {
            const input = document.getElementById('pos-paid-amount');
            if (!input) return;
            if (kind === 'exact') {
                input.value = this.cartTotal().toFixed(2);
            } else {
                input.value = (this.num(input.value) + this.num(kind)).toFixed(2);
            }
            this.updatePaymentCalculations();
        },

        posKeypadKey(key) {
            const input = document.getElementById('pos-paid-amount');
            if (!input) return;
            let value = String(input.value || '');
            if (key === 'clear') {
                value = '';
            } else if (key === 'back') {
                value = value.slice(0, -1);
            } else if (key === '.') {
                if (value.indexOf('.') === -1) value = (value || '0') + '.';
            } else {
                const parts = value.split('.');
                if (parts.length > 1 && parts[1].length >= 2) return;
                if (value === '0') value = '';
                if (value.length >= 10) return;
                value += key;
            }
            input.value = value;
            this.updatePaymentCalculations();
        },

        addSplitRow(method, currency, amount) {
            const wrap = document.getElementById('pos-split-rows');
            if (!wrap) return;
            const row = document.createElement('div');
            row.className = 'pos-split-row';
            row.style.cssText = 'display:grid;grid-template-columns:1fr 1fr auto;gap:6px;margin-bottom:6px;';
            const methods = [{ v: 'cash', l: 'نقدي' }, { v: 'card', l: 'بطاقة' }, { v: 'transfer', l: 'تحويل' }, { v: 'cheque', l: 'شيك' }];
            row.innerHTML = '<select class="form-select ps-method">' + this.options(methods, 'v', 'l', method || 'cash', '') + '</select>'
                + '<input type="number" step="0.5" min="0" class="form-control ps-amount" value="' + (amount || '') + '" placeholder="المبلغ">'
                + '<button type="button" class="btn btn-sm btn-danger" onclick="this.closest(\'.pos-split-row\').remove(); LeviPOS.updatePaymentCalculations();">×</button>';
            wrap.appendChild(row);
            row.querySelectorAll('select, input').forEach(function (el) {
                el.addEventListener('input', function () { this.updatePaymentCalculations(); }.bind(this));
                el.addEventListener('change', function () { this.updatePaymentCalculations(); }.bind(this));
            }, this);
        },

        readSplitPayments() {
            const rows = document.querySelectorAll('#pos-split-rows .pos-split-row');
            const payments = [];
            const currency = this.boot().baseCurrency || 'LYD';
            rows.forEach(function (row) {
                const method = row.querySelector('.ps-method').value;
                const amount = this.num(row.querySelector('.ps-amount').value);
                if (amount > 0) {
                    payments.push({
                        payment_method: method,
                        currency_code: currency,
                        amount_foreign: amount,
                        reference_no: ''
                    });
                }
            }, this);
            return payments;
        },

        updatePaymentCalculations() {
            const methodEl = document.getElementById('pos-payment-method');
            const paidEl = document.getElementById('pos-paid-amount');
            const total = this.cartTotal();
            const method = methodEl ? methodEl.value : 'cash';
            let paidBase = 0;
            if (method === 'split') {
                paidBase = this.readSplitPayments().reduce(function (sum, p) {
                    return sum + this.num(p.amount_foreign);
                }, 0);
            } else if (method !== 'credit') {
                paidBase = paidEl ? this.num(paidEl.value) : 0;
            }
            const change = Math.max(0, paidBase - total);
            const remaining = Math.max(0, total - paidBase);
            const changeEl = document.getElementById('pos-summary-change');
            const remainingEl = document.getElementById('pos-summary-remaining');
            if (changeEl) changeEl.textContent = this.money(change);
            if (remainingEl) remainingEl.textContent = this.money(remaining);
        },

        async posCustomerSearch() {
            const input = document.getElementById('pos-customer-search');
            if (!input) return;
            const box = document.getElementById('pos-customer-suggest');
            const q = input.value.trim();
            if (q.length < 2) {
                this.state.customerMap = {};
                this.state.customerResults = [];
                if (box) { box.innerHTML = ''; box.style.display = 'none'; }
                return;
            }
            const data = await this.apiGet('pos.customers_search', { q: q });
            if (!data) return;
            const customers = data.customers || [];
            this.state.customerMap = {};
            this.state.customerResults = customers;
            if (!box) return;
            box.innerHTML = '';
            if (!customers.length) {
                box.innerHTML = '<div class="pos-suggest-empty">لا توجد نتائج مطابقة.</div>';
                box.style.display = 'block';
                return;
            }
            const self = this;
            customers.forEach(function (customer) {
                const label = (customer.name || '') + ' - ' + (customer.phone || '');
                self.state.customerMap[label] = customer;
                const debt = self.num(customer.current_debt);
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'pos-suggest-item';
                item.innerHTML = '<strong>' + self.escapeHtml(customer.name || '') + '</strong>'
                    + '<span>' + self.escapeHtml(customer.phone || 'بدون هاتف')
                    + (debt > 0 ? ' • دين: ' + self.money(debt) : '') + '</span>';
                item.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                    self.choosePosCustomer(customer);
                });
                box.appendChild(item);
            });
            box.style.display = 'block';
        },

        choosePosCustomer(customer) {
            this.state.customer = customer;
            const input = document.getElementById('pos-customer-search');
            const hidden = document.getElementById('pos-customer-id');
            if (input) input.value = (customer.name || '') + ' - ' + (customer.phone || '');
            if (hidden) hidden.value = customer.id;
            this.hidePosCustomerSuggest();
        },

        hidePosCustomerSuggest() {
            const box = document.getElementById('pos-customer-suggest');
            if (box) box.style.display = 'none';
        },

        selectPosCustomer() {
            const input = document.getElementById('pos-customer-search');
            const hidden = document.getElementById('pos-customer-id');
            if (!input) return;
            const customer = this.state.customerMap[input.value.trim()];
            if (customer) {
                this.choosePosCustomer(customer);
            } else {
                this.state.customer = null;
                if (hidden) hidden.value = '';
            }
        },

        clearPosCustomer() {
            this.state.customer = null;
            this.state.customerResults = [];
            const input = document.getElementById('pos-customer-search');
            const hidden = document.getElementById('pos-customer-id');
            if (input) input.value = '';
            if (hidden) hidden.value = '';
            this.hidePosCustomerSuggest();
        },

        async submitPosSale(isHold) {
            if (this.state.isSubmitting) return;
            if (!this.state.cart.length) {
                this.toast('السلة فارغة. أضف منتجًا أولًا.', 'warning');
                return;
            }
            if (!isHold) {
                // Confirm the live shift state before sending the sale.
                const shift = await this.apiGet('pos.session_status');
                if (shift) this.state.session = shift.my_session || shift.session || null;
                if (!this.state.session) {
                    this.refreshSessionBadge();
                    this.toast('لا يمكن تسجيل البيع بدون فتح وردية الصندوق. يرجى فتح وردية أولاً.', 'warning');
                    return;
                }
            }
            const methodEl = document.getElementById('pos-payment-method');
            const paidEl = document.getElementById('pos-paid-amount');
            const prescriptionEl = document.getElementById('pos-prescription');
            const prescriptionId = prescriptionEl && prescriptionEl.value ? Number(prescriptionEl.value) : null;
            const method = methodEl ? methodEl.value : 'cash';
            const currency = this.boot().baseCurrency || 'LYD';
            let payments = [];
            let paidAmount = 0;
            if (method === 'split') {
                payments = this.readSplitPayments();
                if (!payments.length) {
                    this.toast('أضف سطر دفع واحدًا على الأقل في الدفع المختلط.', 'warning');
                    return;
                }
            } else if (method === 'credit') {
                payments = [];
                paidAmount = 0;
            } else {
                paidAmount = paidEl ? this.num(paidEl.value) : 0;
                payments = [{
                    payment_method: method,
                    currency_code: currency,
                    amount_foreign: paidAmount,
                    reference_no: ''
                }];
            }
            if (method !== 'credit' && method !== 'split' && paidAmount <= 0 && !this.state.customer) {
                this.toast('المبلغ المدفوع صفر. حدد عميلًا لتسجيل الفاتورة آجلة.', 'warning');
                return;
            }
            const payload = {
                items: this.state.cart.map(function (item) {
                    return {
                        product_id: item.product_id,
                        variant_id: item.variant_id,
                        quantity: item.quantity,
                        unit_price: item.unit_price,
                        discount_amount: item.discount_amount,
                        serial_or_imei: item.serial_or_imei || ''
                    };
                }),
                customer_id: this.state.customer ? this.state.customer.id : null,
                prescription_id: prescriptionId,
                invoice_discount: this.cartInvoiceDiscount(),
                payment_method: method,
                currency_code: currency,
                paid_amount: paidAmount,
                payments: payments,
                is_hold: isHold ? 1 : 0,
                held_sale_id: this.state.heldSaleId || null,
                client_uuid: this.ensureClientUuid(),
                notes: ''
            };
            this.state.isSubmitting = true;
            const button = document.getElementById('btn-pos-checkout');
            if (button) button.disabled = true;
            const result = await this.apiPost('pos.checkout', payload);
            this.state.isSubmitting = false;
            if (button) button.disabled = false;
            if (!result) return;
            this.toast(isHold ? 'تم تعليق الفاتورة.' : 'تم تسجيل البيع بنجاح.', 'success');
            const saleId = result.sale_id || result.id || (result.sale ? result.sale.id : null);
            if (!isHold && prescriptionId && saleId) {
                this.apiPost('prescriptions.dispense', { prescription_id: prescriptionId, sale_id: saleId });
            }
            const shouldPrint = !isHold && document.getElementById('pos-auto-print') && document.getElementById('pos-auto-print').checked;
            this.state.cart = [];
            this.state.heldSaleId = null;
            this.state.clientUuid = null;
            this.clearPosCustomer();
            const discountEl = document.getElementById('pos-invoice-discount');
            if (discountEl) discountEl.value = '0';
            const paidReset = document.getElementById('pos-paid-amount');
            if (paidReset) paidReset.value = '0';
            const splitRows = document.getElementById('pos-split-rows');
            if (splitRows) {
                splitRows.innerHTML = '';
                if (method === 'split') {
                    this.addSplitRow('cash', currency || this.boot().baseCurrency || 'LYD', '');
                }
            }
            this.renderCart();
            this.state.posMetaLoaded = false;
            this.searchPosProducts();
            this.refreshSessionBadge();
            if (isHold || payload.held_sale_id) this.refreshHeldBadge();
            if (!isHold && result.open_cash_drawer) this.openCashDrawer();
            if (shouldPrint && saleId) {
                window.open('print_receipt.php?sale_id=' + encodeURIComponent(saleId), '_blank');
            }
        },

        /* =================================================================
         * Products
         * ================================================================= */
        async ensureReferenceData() {
            if (!this.state.categories.length || !this.state.brands.length) {
                await this.loadReferenceData();
            }
        },

        async ensureProductCatalog(force) {
            if (!force && this.state.allProducts.length) return this.state.allProducts;
            let data = await this.apiGet('products.list', { limit: 500 });
            if (!data) data = await this.apiGet('pos.search_products', { limit: 500 });
            this.state.allProducts = data ? (data.items || []) : [];
            return this.state.allProducts;
        },

        async loadProductOptions(selectId) {
            const products = await this.ensureProductCatalog();
            const el = document.getElementById(selectId);
            if (el) el.innerHTML = this.productOptionHtml(products, el.value);
        },

        async loadProductStats() {
            const canExpiry = this.has('expiry.view') || this.has('batches.manage') || this.perm('inventory_view');
            const requests = [
                this.apiGet('products.list', { archived: 0, limit: 500 }),
                this.apiGet('products.list', { archived: 1, limit: 500 })
            ];
            if (canExpiry) requests.push(this.apiGet('expiry.alerts'));
            const results = await Promise.all(requests);
            const activeData = results[0];
            if (!activeData) return;
            const active = activeData.items || [];
            const archived = (results[1] && results[1].items) || [];
            const low = active.filter(function (p) {
                return this.num(p.stock_qty) <= this.num(p.min_stock_level);
            }, this).length;
            const expiryCounts = canExpiry && results[2] && results[2].counts ? results[2].counts : null;
            const set = function (id, value) {
                const el = document.getElementById(id);
                if (el) el.textContent = String(value);
            };
            set('products-stat-total', active.length);
            set('products-stat-low', low);
            set('products-stat-expiry', expiryCounts ? this.num(expiryCounts.near_expiry) : '—');
            set('products-stat-archived', archived.length);
        },

        async loadProducts() {
            this.loadProductStats();
            const search = document.getElementById('products-search');
            const q = search ? search.value.trim() : '';
            const archived = !!this.state.showArchivedProducts;
            const data = await this.apiGet('products.list', { q: q, limit: 300, archived: archived ? 1 : 0 });
            if (!data) return;
            this.state.products = data.items || [];
            const self = this;
            const columns = [
                { label: 'المنتج', render: function (r) {
                    const thumb = r.image_path
                        ? '<img src="' + self.escapeHtml(self.mediaUrl(r.image_path)) + '" alt="" style="width:32px;height:32px;object-fit:cover;border-radius:6px;margin-inline-end:6px;vertical-align:middle;border:1px solid var(--levi-border);">'
                        : '';
                    return '<span class="prodx-prod-em" aria-hidden="true">' + self.posProductEmoji(r) + '</span>'
                        + thumb + '<strong>' + self.escapeHtml(r.product_name) + '</strong>'
                        + (r.variant_name ? '<br><span class="muted">' + self.escapeHtml(r.variant_name) + '</span>' : '');
                } },
                { label: 'SKU', render: function (r) { return self.escapeHtml(r.sku || ''); } },
                { label: 'التصنيف', render: function (r) { return self.escapeHtml(r.category_name || ''); } },
                { label: 'الماركة', render: function (r) { return self.escapeHtml(r.brand_name || ''); } }
            ];
            if (this.has('products.view_cost')) {
                columns.push({ label: 'التكلفة', render: function (r) { return '<span class="muted">' + self.money(r.purchase_cost) + '</span>'; } });
            }
            columns.push(
                { label: 'سعر البيع', render: function (r) { return '<span class="money">' + self.money(r.selling_price) + '</span>'; } },
                { label: 'المخزون', render: function (r) { return self.badge(String(self.num(r.stock_qty)), self.num(r.stock_qty) <= self.num(r.min_stock_level) ? 'red' : 'green'); } },
                { label: 'المادة الفعالة', render: function (r) { return self.escapeHtml(r.active_ingredient || '-'); } },
                { label: 'الانتهاء', render: function (r) { return r.nearest_expiry ? self.badge(self.dateOnly(r.nearest_expiry), self.num(r.expiry_days_left) < 0 ? 'red' : (self.num(r.expiry_days_left) <= self.num(self.boot().expiryAlertDays || 30) ? 'amber' : 'green')) : '-'; } }
            );
            if (archived) {
                columns.push({ label: 'الحالة', render: function (r) {
                    return Number(r.is_archived) === 1 ? self.badge('مؤرشف', 'red') : self.badge('محذوف/معطل', 'gray');
                } });
            }
            columns.push(
                { label: 'إجراءات', render: function (r) {
                    let html = '<div class="table-actions">';
                    if (self.has('products.manage')) {
                        if (archived) {
                            html += '<button class="btn btn-sm btn-success" onclick="LeviPOS.restoreProduct(' + r.product_id + ')">استعادة</button>';
                            html += '<button class="btn btn-sm btn-danger" onclick="LeviPOS.purgeArchivedProduct(' + r.product_id + ')">حذف نهائي</button>';
                        } else {
                            html += '<button class="btn btn-sm btn-outline" onclick="LeviPOS.openProductModal(' + r.product_id + ')">تعديل</button>';
                            html += '<button class="btn btn-sm btn-outline" onclick="LeviPOS.openBarcodeModal(' + r.product_id + ')">باركود</button>';
                            html += '<button class="btn btn-sm btn-danger" onclick="LeviPOS.deleteProduct(' + r.product_id + ')">حذف</button>';
                        }
                    }
                    html += '</div>';
                    return html;
                } }
            );
            if (this.boot().isOwner) {
                columns.unshift({
                    label: '',
                    labelHtml: '<input type="checkbox" title="تحديد الكل" data-check-all="product" onchange="LeviPOS.toggleAllProductChecks(this.checked)">',
                    render: function (r) {
                        return '<input type="checkbox" class="row-check" data-check="product" value="' + self.num(r.product_id) + '">';
                    }
                });
            }
            this.renderTable('products-table', columns, this.state.products, archived
                ? 'لا توجد منتجات مؤرشفة أو محذوفة.'
                : 'لا توجد منتجات. أضف منتجًا جديدًا أو استورد ملف CSV.');
        },

        toggleArchivedProducts() {
            this.state.showArchivedProducts = !this.state.showArchivedProducts;
            const btn = document.getElementById('products-archived-toggle');
            if (btn) {
                btn.textContent = this.state.showArchivedProducts ? '↩️ عرض المنتجات النشطة' : '🗄️ الأدوية المؤرشفة';
                btn.classList.toggle('btn-primary', this.state.showArchivedProducts);
                btn.classList.toggle('btn-outline', !this.state.showArchivedProducts);
                btn.classList.toggle('is-primary', this.state.showArchivedProducts);
            }
            this.loadProducts();
        },

        /* =================================================================
         * Owner bulk-selection helpers (products / invoices / returns)
         * ================================================================= */
        toggleAllTableChecks(kind, checked) {
            const boxes = Array.prototype.slice.call(document.querySelectorAll('[data-check="' + kind + '"]'));
            if (!boxes.length) return;
            let target = checked;
            if (typeof target !== 'boolean') {
                target = !boxes.every(function (box) { return box.checked; });
            }
            boxes.forEach(function (box) { box.checked = target; });
            const all = document.querySelector('[data-check-all="' + kind + '"]');
            if (all) all.checked = target;
        },

        toggleAllProductChecks(checked) {
            this.toggleAllTableChecks('product', checked);
        },

        selectedIds(kind) {
            const ids = [];
            document.querySelectorAll('[data-check="' + kind + '"]:checked').forEach(function (box) {
                const value = parseInt(box.value, 10);
                if (value > 0 && ids.indexOf(value) === -1) ids.push(value);
            });
            return ids;
        },

        async bulkDeleteProducts() {
            if (!this.boot().isOwner) {
                this.toast('الحذف الجماعي للمنتجات مخصص للمدير العام.', 'warning');
                return;
            }
            const ids = this.selectedIds('product');
            if (!ids.length) {
                this.toast('حدد منتجًا واحدًا على الأقل من الجدول.', 'warning');
                return;
            }
            const archivedView = !!this.state.showArchivedProducts;
            const message = archivedView
                ? 'سيتم حذف ' + ids.length + ' منتج مؤرشف نهائيًا من النظام.\nالمنتجات المرتبطة بفواتير سيتم تجاوزها.\nهل تريد المتابعة؟'
                : 'سيتم حذف ' + ids.length + ' منتج نهائيًا.\nالمنتجات المرتبطة بفواتير أو مشتريات ستُؤرشف تلقائيًا بدل حذفها.\nهل تريد المتابعة؟';
            if (!window.confirm(message)) {
                return;
            }
            const result = await this.apiPost('products.bulk_delete', { product_ids: ids });
            if (!result) return;
            this.toast(
                'تم التنفيذ: ' + this.num(result.permanently_deleted) + ' حذف نهائي، '
                + this.num(result.soft_deleted) + ' محذوف، '
                + this.num(result.archived) + ' مؤرشف'
                + (this.num(result.blocked) > 0 ? '، ' + this.num(result.blocked) + ' تجاوزته الفواتير.' : '.'),
                'success'
            );
            this.loadProducts();
        },

        async bulkDeleteInvoices() {
            if (!this.boot().isOwner) {
                this.toast('حذف الفواتير مخصص للمدير العام.', 'warning');
                return;
            }
            const ids = this.selectedIds('invoice');
            if (!ids.length) {
                this.toast('حدد فاتورة واحدة على الأقل من الجدول.', 'warning');
                return;
            }
            const phrase = window.prompt('سيتم حذف ' + ids.length + ' فاتورة نهائيًا مع المدفوعات والمرتجعات والضمانات المرتبطة بها.\nاكتب العبارة التالية للتأكيد: حذف نهائي');
            if (phrase === null) return;
            if (phrase.trim() !== 'حذف نهائي') {
                this.toast('عبارة التأكيد غير صحيحة. لم يتم حذف أي فاتورة.', 'error');
                return;
            }
            const result = await this.apiPost('owner.cleanup_purge', {
                groups: [],
                sale_ids: ids,
                confirmation: phrase.trim()
            });
            if (!result) return;
            this.toast('تم حذف ' + this.num(result.deleted && result.deleted.sales) + ' فاتورة نهائيًا.', 'success');
            this.loadInvoices();
        },

        async bulkDeleteReturns() {
            if (!this.boot().isOwner) {
                this.toast('حذف المرتجعات مخصص للمدير العام.', 'warning');
                return;
            }
            const ids = this.selectedIds('return');
            if (!ids.length) {
                this.toast('حدد مرتجعًا واحدًا على الأقل من الجدول.', 'warning');
                return;
            }
            const phrase = window.prompt('سيتم حذف ' + ids.length + ' مرتجع نهائيًا وإعادة تحديث الفواتير المرتبطة.\nاكتب العبارة التالية للتأكيد: حذف نهائي');
            if (phrase === null) return;
            if (phrase.trim() !== 'حذف نهائي') {
                this.toast('عبارة التأكيد غير صحيحة. لم يتم حذف أي مرتجع.', 'error');
                return;
            }
            const result = await this.apiPost('owner.cleanup_purge', {
                groups: [],
                return_ids: ids,
                confirmation: phrase.trim()
            });
            if (!result) return;
            this.toast('تم حذف ' + this.num(result.deleted && result.deleted.sale_returns) + ' مرتجع نهائيًا.', 'success');
            this.loadReturns();
        },

        async openProductModal(id) {
            await this.ensureReferenceData();
            const product = id ? await this.apiGet('products.get', { product_id: id }) : null;
            if (id && !product) return;
            const isEdit = !!product;
            const categoryOptions = this.options(this.state.categories, 'id', 'name', isEdit ? product.category_id : (this.state.categories[0] ? this.state.categories[0].id : ''), 'اختر التصنيف...');
            const brandOptions = this.options(this.state.brands, 'id', 'name', isEdit ? product.brand_id : '', 'بدون ماركة');
            const val = function (key, fallback) {
                return this.escapeHtml(product && product[key] != null ? product[key] : (fallback == null ? '' : fallback));
            }.bind(this);
            const canAdjustStock = this.stockAdjustAllowed();
            const canViewCost = this.has('products.view_cost');
            const boot = this.boot() || {};
            const defaultMinStock = boot.defaultMinStock != null ? boot.defaultMinStock : 5;
            const hasVariants = isEdit && ((Number(product.has_variants) === 1) || (Array.isArray(product.variants) && product.variants.length > 0));
            const currentStock = isEdit && product.stock && product.stock.quantity != null ? product.stock.quantity : 0;
            const body = ''
                + '<form id="product-form" onsubmit="return false;">'
                + '<div class="form-grid">'
                + '<div><label>اسم المنتج *</label><input id="pf-name" class="form-control" value="' + val('name') + '" required></div>'
                + '<div><label>SKU *</label><input id="pf-sku" class="form-control" value="' + val('sku') + '" required></div>'
                + '<div><label>الباركود</label><input id="pf-barcode" class="form-control" value="' + val('barcode') + '"></div>'
                + '<div><label>التصنيف العلاجي *</label><select id="pf-category" class="form-select">' + categoryOptions + '</select></div>'
                + '<div><label>شركة الدواء</label><select id="pf-brand" class="form-select">' + brandOptions + '</select></div>'
                + (canViewCost ? '<div><label>سعر التكلفة</label><input type="number" step="0.01" min="0" id="pf-cost" class="form-control" value="' + val('purchase_cost', '0') + '"></div>' : '')
                + '<div><label>سعر البيع *</label><input type="number" step="0.01" min="0" id="pf-price" class="form-control" value="' + val('selling_price', '0') + '" required></div>'
                + '<div><label>سعر الجملة</label><input type="number" step="0.01" min="0" id="pf-wholesale" class="form-control" value="' + val('wholesale_price') + '"></div>'
                + '<div><label>حد المخزون الأدنى</label><input type="number" min="0" id="pf-min-stock" class="form-control" value="' + val('min_stock_level', String(defaultMinStock)) + '"></div>'
                + '<div><label>المادة الفعالة</label><input id="pf-ingredient" class="form-control" value="' + val('active_ingredient') + '"></div>'
                + '<div><label>التركيز / الجرعة</label><input id="pf-strength" class="form-control" value="' + val('strength') + '"></div>'
                + '<div><label>الشكل الصيدلاني</label><input id="pf-dosage-form" class="form-control" value="' + val('dosage_form') + '"></div>'
                + '<div><label>الشركة المصنّعة</label><input id="pf-manufacturer" class="form-control" value="' + val('manufacturer') + '"></div>'
                + '<div><label>رقم التسجيل</label><input id="pf-registration" class="form-control" value="' + val('registration_number') + '"></div>'
                + '<div><label>بلد المنشأ</label><input id="pf-origin" class="form-control" value="' + val('country_of_origin') + '"></div>'
                + '<div><label>شروط التخزين</label><input id="pf-storage" class="form-control" value="' + val('storage_conditions') + '"></div>'
                + (isEdit ? (hasVariants ? '' : (canAdjustStock ? '<div><label>المخزون الحالي</label><input type="number" min="0" id="pf-stock-qty" class="form-control" data-original="' + this.escapeHtml(currentStock) + '" value="' + this.escapeHtml(currentStock) + '"></div>' : '')) : '<div><label>الكمية الافتتاحية</label><input type="number" min="0" id="pf-initial-qty" class="form-control" value="0"></div>')
                + '</div>'
                + '<div class="mt-3"><label>الوصف</label><textarea id="pf-description" class="form-control" rows="2">' + val('description') + '</textarea></div>'
                + '<div class="mt-2"><label><input type="checkbox" id="pf-prescription" ' + (Number(product && product.requires_prescription) === 1 ? 'checked' : '') + '> لا يُصرف إلا بروشتة طبية</label></div>'
                + '<div class="mt-2"><label>صورة المنتج</label>'
                + '<div style="display:flex;align-items:center;gap:10px;">'
                + (isEdit && product.image_path ? '<img src="' + this.escapeHtml(this.mediaUrl(product.image_path)) + '" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid var(--levi-border);">' : '')
                + '<input type="file" id="pf-image" class="form-control" accept="image/png,image/jpeg,image/webp,image/gif">'
                + '</div><div class="muted" style="font-size:11px;">PNG / JPG / WEBP / GIF — بحد أقصى 3 ميغابايت.</div></div>'
                + '<div class="mt-2"><label><input type="checkbox" id="pf-active" ' + (!isEdit || Number(product.is_active) === 1 ? 'checked' : '') + '> منتج مفعل للبيع</label></div>'
                + '<div class="mt-3" style="border-top:1px solid var(--levi-border);padding-top:10px;">'
                + '<div class="d-flex justify-content-between align-items-center mb-2"><strong style="font-size:13px;">خيارات التغليف / التركيز (للتوافق مع المخزون)</strong>'
                + '<button type="button" class="btn btn-sm btn-outline" onclick="LeviPOS.addVariantRow()">+ خيار</button></div>'
                + '<div id="pf-variants"></div>'
                + '<div class="muted" style="font-size:11px;">اترك الخيارات فارغة لمنتج بسيط. لكل خيار SKU مستقل ومخزون مستقل.</div>'
                + '</div>'
                + '</form>';
            const footer = '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إلغاء</button>'
                + '<button type="button" class="btn btn-primary" onclick="LeviPOS.submitProduct(' + (isEdit ? product.id : 'null') + ')">حفظ المنتج</button>';
            this.openModal(isEdit ? 'تعديل المنتج' : 'إضافة منتج جديد', body, footer, 'wide');
            const variants = isEdit && Array.isArray(product.variants) ? product.variants : [];
            variants.forEach(function (variant) { this.addVariantRow(variant); }, this);
        },

        addVariantRow(variant) {
            const wrap = document.getElementById('pf-variants');
            if (!wrap) return;
            variant = variant || {};
            const row = document.createElement('div');
            row.className = 'pf-variant-row';
            row.setAttribute('data-id', variant.id || '');
            row.style.cssText = 'display:grid;grid-template-columns:1.4fr 1fr 1fr .8fr .8fr .8fr auto;gap:6px;margin-bottom:6px;align-items:end;';
            const esc = function (v) { return this.escapeHtml(v == null ? '' : v); }.bind(this);
            const canAdjust = this.stockAdjustAllowed();
            const canViewCost = this.has('products.view_cost');
            const currentQty = variant.id ? (variant.quantity || 0) : 0;
            const productCost = (document.getElementById('pf-cost') || {}).value || '0';
            const productPrice = (document.getElementById('pf-price') || {}).value || '0';
            const variantCost = variant.purchase_cost == null || variant.purchase_cost === '' ? productCost : variant.purchase_cost;
            const variantPrice = variant.selling_price == null || variant.selling_price === '' ? productPrice : variant.selling_price;
            row.innerHTML = '<div><label style="font-size:10.5px;">اسم الخيار</label><input class="form-control pv-name" value="' + esc(variant.variant_name) + '"></div>'
                + '<div><label style="font-size:10.5px;">SKU</label><input class="form-control pv-sku" value="' + esc(variant.sku) + '"></div>'
                + '<div><label style="font-size:10.5px;">الباركود</label><input class="form-control pv-barcode" value="' + esc(variant.barcode) + '"></div>'
                + (canViewCost
                    ? '<div><label style="font-size:10.5px;">التكلفة</label><input type="number" step="0.01" class="form-control pv-cost" value="' + esc(variantCost) + '"></div>'
                    : '<div></div>')
                + '<div><label style="font-size:10.5px;">البيع</label><input type="number" step="0.01" class="form-control pv-price" value="' + esc(variantPrice) + '"></div>'
                + '<div><label style="font-size:10.5px;">' + (variant.id ? 'المخزون' : 'افتتاحي') + '</label><input type="number" min="0" class="form-control pv-qty" data-original="' + esc(currentQty) + '" value="' + esc(currentQty) + '" ' + (variant.id && !canAdjust ? 'disabled' : '') + '></div>'
                + '<div><button type="button" class="btn btn-sm btn-danger" onclick="this.closest(\'.pf-variant-row\').remove()">×</button></div>';
            wrap.appendChild(row);
        },

        async submitProduct(id) {
            const value = function (fieldId) {
                const el = document.getElementById(fieldId);
                return el ? el.value.trim() : '';
            };
            const name = value('pf-name');
            const sku = value('pf-sku');
            if (!name || !sku) { this.toast('اسم المنتج و SKU مطلوبان.', 'warning'); return; }
            const canAdjustStock = this.stockAdjustAllowed();
            const canViewCost = this.has('products.view_cost');
            const variants = [];
            document.querySelectorAll('#pf-variants .pf-variant-row').forEach(function (row) {
                const vName = row.querySelector('.pv-name').value.trim();
                const vSku = row.querySelector('.pv-sku').value.trim();
                if (!vName && !vSku) return;
                const qtyEl = row.querySelector('.pv-qty');
                const qtyValue = qtyEl ? qtyEl.value.trim() : '';
                const item = {
                    variant_name: vName,
                    sku: vSku,
                    barcode: row.querySelector('.pv-barcode').value.trim(),
                    selling_price: row.querySelector('.pv-price').value
                };
                if (canViewCost) {
                    const costEl = row.querySelector('.pv-cost');
                    if (costEl) item.purchase_cost = costEl.value;
                }
                const vid = row.getAttribute('data-id');
                if (vid) {
                    item.id = Number(vid);
                    if (canAdjustStock && qtyEl && !qtyEl.disabled && qtyValue !== (qtyEl.getAttribute('data-original') || '')) {
                        item.stock_quantity = qtyValue === '' ? 0 : qtyValue;
                    }
                } else {
                    item.initial_quantity = qtyValue === '' ? 0 : qtyValue;
                }
                variants.push(item);
            });
            const payload = {
                name: name,
                sku: sku,
                barcode: value('pf-barcode'),
                category_id: value('pf-category'),
                brand_id: value('pf-brand') || null,
                selling_price: value('pf-price') || 0,
                wholesale_price: value('pf-wholesale'),
                min_stock_level: value('pf-min-stock') || 0,
                active_ingredient: value('pf-ingredient'),
                strength: value('pf-strength'),
                dosage_form: value('pf-dosage-form'),
                manufacturer: value('pf-manufacturer'),
                registration_number: value('pf-registration'),
                country_of_origin: value('pf-origin'),
                storage_conditions: value('pf-storage'),
                requires_prescription: document.getElementById('pf-prescription') && document.getElementById('pf-prescription').checked ? 1 : 0,
                description: value('pf-description'),
                is_active: document.getElementById('pf-active') && document.getElementById('pf-active').checked ? 1 : 0,
                variants: variants
            };
            if (canViewCost) {
                payload.purchase_cost = value('pf-cost') || 0;
            }
            if (!id) {
                payload.initial_quantity = value('pf-initial-qty') || 0;
            }
            if (id) {
                payload.product_id = id;
                if (canAdjustStock) {
                    const stockEl = document.getElementById('pf-stock-qty');
                    if (stockEl && !stockEl.disabled) {
                        const stockValue = stockEl.value.trim();
                        if ((stockValue === '' ? '0' : stockValue) !== (stockEl.getAttribute('data-original') || '')) {
                            payload.stock_quantity = stockValue === '' ? 0 : stockValue;
                        }
                    }
                }
            }
            const result = await this.apiPost(id ? 'products.update' : 'products.create', payload);
            if (!result) return;
            const imageInput = document.getElementById('pf-image');
            const imageFile = imageInput && imageInput.files && imageInput.files.length ? imageInput.files[0] : null;
            const savedId = result.product_id || id;
            if (imageFile && savedId) {
                const form = new FormData();
                form.append('image', imageFile);
                form.append('product_id', savedId);
                const upload = await this.apiUpload('products.image_upload', form);
                if (!upload) this.toast('تم حفظ المنتج، لكن تعذر رفع الصورة.', 'warning');
            }
            this.toast(id ? 'تم تحديث المنتج.' : 'تمت إضافة المنتج.', 'success');
            this.closeModal();
            this.state.allProducts = [];
            this.state.posMetaLoaded = false;
            this.loadProducts();
        },

        async deleteProduct(id) {
            if (!window.confirm('هل تريد حذف هذا المنتج؟\nإذا كان مرتبطًا بفواتير سابقة فسيتم أرشفته تلقائيًا للحفاظ على السجلات، ويمكنك استعادته من «المنتجات المؤرشفة».')) return;
            const result = await this.apiPost('products.archive', { product_id: id });
            if (!result) return;
            this.toast('تم حذف المنتج. يمكنك استعادته من «المنتجات المؤرشفة».', 'success');
            this.state.allProducts = [];
            this.loadProducts();
        },

        async purgeArchivedProduct(id) {
            if (!window.confirm('سيتم حذف هذا المنتج المؤرشف نهائيًا من قاعدة البيانات. لا يمكن التراجع.\nهل أنت متأكد؟')) return;
            const result = await this.apiPost('products.purge_archived', { product_ids: [id] });
            if (!result) return;
            if (this.num(result.deleted) > 0) {
                this.toast('تم حذف المنتج نهائيًا من الأرشيف.', 'success');
            } else {
                this.toast('لم يُحذف المنتج لأنه مرتبط بفواتير سابقة.', 'warning');
            }
            this.state.allProducts = [];
            this.loadProducts();
        },

        async restoreProduct(id) {
            if (!window.confirm('هل تريد استعادة هذا المنتج وإعادة تفعيله؟')) return;
            const result = await this.apiPost('products.restore', { product_id: id });
            if (!result) return;
            this.toast('تمت استعادة المنتج بنجاح.', 'success');
            this.state.allProducts = [];
            this.loadProducts();
        },

        openBarcodeModal(id) {
            const product = (this.state.products || []).find(function (p) { return Number(p.product_id) === Number(id); });
            if (!product) { this.toast('المنتج غير موجود في القائمة الحالية.', 'warning'); return; }
            const url = 'print_barcode.php?sku=' + encodeURIComponent(product.sku || '')
                + '&name=' + encodeURIComponent(product.product_name || '')
                + '&price=' + encodeURIComponent(product.selling_price || '');
            window.open(url, '_blank');
        },

        openImportModal() {
            const body = ''
                + '<div class="muted" style="font-size:12.5px;margin-bottom:10px;">'
                + 'الملف يجب أن يكون CSV بترميز UTF-8. الأعمدة: name, sku, barcode, category, brand, purchase_cost, selling_price, wholesale_price, min_stock_level, initial_quantity, active_ingredient, strength, dosage_form, manufacturer, registration_number, country_of_origin, storage_conditions, requires_prescription, description.'
                + '</div>'
                + '<div class="mb-2"><label>ملف CSV</label><input type="file" id="imp-file" class="form-control" accept=".csv,text/csv"></div>'
                + '<div class="mb-2"><label>أو الصق محتوى CSV هنا</label><textarea id="imp-text" class="form-control" rows="8" placeholder="name,sku,barcode,..."></textarea></div>'
                + '<div class="mb-2"><label><input type="checkbox" id="imp-create-refs" checked> إنشاء التصنيفات/الماركات غير الموجودة تلقائيًا</label></div>'
                + '<div id="imp-result"></div>';
            const footer = '<button type="button" class="btn btn-outline" onclick="LeviPOS.downloadImportTemplate()">تحميل القالب</button>'
                + '<button type="button" class="btn btn-outline" onclick="LeviPOS.runImport(false)">تحقق</button>'
                + '<button type="button" class="btn btn-primary" onclick="LeviPOS.runImport(true)">تنفيذ الاستيراد</button>';
            this.openModal('استيراد المنتجات من CSV', body, footer, 'wide');
        },

        async downloadImportTemplate() {
            const data = await this.apiGet('import.template');
            if (!data) return;
            this.downloadText('levi_products_template.csv', data.csv || '');
        },

        parseCsv(text) {
            const rows = [];
            let row = [];
            let field = '';
            let inQuotes = false;
            const pushField = function () {
                row.push(field);
                field = '';
            };
            const pushRow = function () {
                if (row.length && !(row.length === 1 && row[0] === '')) rows.push(row);
                row = [];
            };
            for (let i = 0; i < text.length; i++) {
                const ch = text[i];
                if (inQuotes) {
                    if (ch === '"') {
                        if (text[i + 1] === '"') { field += '"'; i++; }
                        else { inQuotes = false; }
                    } else {
                        field += ch;
                    }
                } else if (ch === '"') {
                    inQuotes = true;
                } else if (ch === ',') {
                    pushField();
                } else if (ch === '\n') {
                    pushField();
                    pushRow();
                } else if (ch === '\r') {
                    // ignore
                } else {
                    field += ch;
                }
            }
            pushField();
            pushRow();
            if (!rows.length) return [];
            const headers = rows.shift().map(function (h) { return h.trim(); });
            return rows.map(function (cells) {
                const obj = {};
                headers.forEach(function (header, index) { obj[header] = cells[index] != null ? String(cells[index]).trim() : ''; });
                return obj;
            });
        },

        async readImportText() {
            const textarea = document.getElementById('imp-text');
            const fileInput = document.getElementById('imp-file');
            let text = textarea ? textarea.value.trim() : '';
            if (!text && fileInput && fileInput.files && fileInput.files[0]) {
                text = await new Promise(function (resolve, reject) {
                    const reader = new FileReader();
                    reader.onload = function () { resolve(String(reader.result || '')); };
                    reader.onerror = function () { reject(new Error('تعذر قراءة الملف.')); };
                    reader.readAsText(fileInput.files[0], 'UTF-8');
                }).catch(function (err) { this.toast(err.message, 'error'); return ''; }.bind(this));
            }
            return text;
        },

        async runImport(execute) {
            const text = await this.readImportText();
            if (!text) { this.toast('يرجى اختيار ملف CSV أو لصق محتواه.', 'warning'); return; }
            const rows = this.parseCsv(text);
            if (!rows.length) { this.toast('لم يتم العثور على صفوف صالحة في الملف.', 'warning'); return; }
            const createMissing = document.getElementById('imp-create-refs') && document.getElementById('imp-create-refs').checked;
            if (!execute) {
                const result = await this.apiPost('import.validate', { rows: rows });
                if (!result) return;
                let html = '<div class="mb-2">' + this.badge('عدد الصفوف: ' + result.total_rows, 'blue')
                    + ' ' + this.badge('أخطاء: ' + result.error_count, result.error_count ? 'red' : 'green') + '</div>';
                if (result.errors && result.errors.length) {
                    html += '<div class="pill pill-red" style="display:block;margin-bottom:8px;">' + result.errors.map(function (e) { return this.escapeHtml(e); }, this).join('<br>') + '</div>';
                }
                const preview = (result.preview || []).slice(0, 100);
                html += '<div class="table-responsive" style="max-height:260px;overflow:auto;"><table class="table table-sm"><thead><tr><th>السطر</th><th>الاسم</th><th>SKU</th><th>البيع</th><th>الحالة</th></tr></thead><tbody>'
                    + preview.map(function (p) {
                        return '<tr><td>' + p.line + '</td><td>' + this.escapeHtml(p.name) + '</td><td>' + this.escapeHtml(p.sku) + '</td><td>' + this.money(p.selling_price) + '</td><td>' + (p.valid ? this.badge('صالح', 'green') : this.badge('خطأ', 'red')) + '</td></tr>';
                    }, this).join('') + '</tbody></table></div>';
                const resultEl = document.getElementById('imp-result');
                if (resultEl) resultEl.innerHTML = html;
                return;
            }
            if (!window.confirm('سيتم استيراد ' + rows.length + ' صفًا. هل تريد المتابعة؟')) return;
            const result = await this.apiPost('import.products', { rows: rows, create_missing: createMissing ? 1 : 0 });
            if (!result) return;
            this.toast('تم الاستيراد. تمت إضافة ' + (result.created || 0) + ' منتجًا وفشل ' + (result.failed || 0) + '.', 'success');
            this.closeModal();
            this.state.allProducts = [];
            this.state.posMetaLoaded = false;
            this.loadProducts();
        },

        /* =================================================================
         * Inventory
         * ================================================================= */
        async loadInventoryStats() {
            const results = await Promise.all([
                this.apiGet('inventory.list', { limit: 500 }),
                this.apiGet('inventory.valuation'),
                this.apiGet('inventory.low_stock', { limit: 500 })
            ]);
            if (!results[0]) return;
            const items = results[0].items || [];
            const low = (results[2] && results[2].items) || [];
            const out = items.filter(function (r) { return this.num(r.quantity) <= 0; }, this).length;
            const valuation = results[1] ? (results[1].total_value || results[1].inventory_value || 0) : null;
            const set = function (id, value) {
                const el = document.getElementById(id);
                if (el) el.textContent = String(value);
            };
            set('inventory-stat-value', this.has('products.view_cost') && valuation !== null ? this.money(valuation) : '—');
            set('inventory-stat-low', low.length);
            set('inventory-stat-out', out);
            set('inventory-stat-total', items.length);
        },

        async loadInventory() {
            this.loadInventoryStats();
            const search = document.getElementById('inventory-search');
            const q = search ? search.value.trim() : '';
            const results = await Promise.all([
                this.apiGet('inventory.list', { q: q, limit: 300 }),
                this.apiGet('inventory.valuation')
            ]);
            if (!results[0]) return;
            const items = results[0].items || [];
            const valuationEl = document.getElementById('inventory-valuation');
            if (valuationEl) {
                if (this.has('products.view_cost') && results[1]) {
                    valuationEl.textContent = 'قيمة المخزون: ' + this.money(results[1].total_value || results[1].inventory_value || 0);
                } else {
                    valuationEl.textContent = '';
                }
            }
            const self = this;
            const inventoryColumns = [
                { label: 'المنتج', render: function (r) { return '<span class="invx-prod-em" aria-hidden="true">' + self.posProductEmoji(r) + '</span><strong>' + self.escapeHtml(r.product_name) + '</strong>' + (r.variant_name ? ' - ' + self.escapeHtml(r.variant_name) : ''); } },
                { label: 'SKU', render: function (r) { return self.escapeHtml(r.variant_sku || r.sku || ''); } },
                { label: 'التصنيف', render: function (r) { return self.escapeHtml(r.category_name || ''); } },
                { label: 'المخزون', render: function (r) { return self.badge(String(self.num(r.quantity)), self.num(r.quantity) <= self.num(r.variant_min_stock || r.min_stock_level) ? 'red' : 'green'); } }
            ];
            if (this.has('products.view_cost')) {
                inventoryColumns.push({ label: 'متوسط التكلفة', render: function (r) { return self.money(r.average_cost); } });
                inventoryColumns.push({ label: 'قيمة المخزون', render: function (r) { return '<span class="money">' + self.money(r.stock_value) + '</span>'; } });
            }
            inventoryColumns.push({ label: 'إجراءات', render: function (r) { return self.stockAdjustAllowed() ? '<button class="btn btn-sm btn-outline" onclick="LeviPOS.openInventoryAdjustFor(' + r.product_id + ',' + (r.variant_id || 0) + ')">تسوية</button>' : '—'; } });
            this.renderTable('inventory-table', inventoryColumns, items, 'لا توجد أصناف في المخزون.');
        },

        async loadInventoryLow() {
            const data = await this.apiGet('inventory.low_stock', { limit: 200 });
            if (!data) return;
            const self = this;
            this.renderTable('inventory-low-table', [
                { label: 'المنتج', render: function (r) { return '<span class="invx-prod-em" aria-hidden="true">' + self.posProductEmoji(r) + '</span><strong>' + self.escapeHtml(r.product_name) + '</strong>' + (r.variant_name ? ' - ' + self.escapeHtml(r.variant_name) : ''); } },
                { label: 'SKU', render: function (r) { return self.escapeHtml(r.variant_sku || r.sku || ''); } },
                { label: 'المخزون', render: function (r) { return self.badge(String(self.num(r.quantity)), 'red'); } },
                { label: 'الحد الأدنى', render: function (r) { return self.num(r.variant_min_stock || r.min_stock_level); } },
                { label: 'إجراء', render: function (r) { return self.stockAdjustAllowed() ? '<button class="btn btn-sm btn-primary" onclick="LeviPOS.openInventoryAdjustFor(' + r.product_id + ',' + (r.variant_id || 0) + ')">تسوية</button>' : '—'; } }
            ], data.items || [], 'لا توجد أصناف منخفضة المخزون.');
        },

        async loadInventoryTransferOptions() {
            await this.loadProductOptions('trf-product-id');
            const data = await this.apiGet('inventory.transfer_branches');
            if (!data) return;
            const select = document.getElementById('trf-branch-id');
            if (!select) return;
            const currentBranch = Number(this.boot().branchId || 0);
            const branches = (data.branches || []).filter(function (branch) {
                return Number(branch.id) !== currentBranch;
            });
            select.innerHTML = this.options(branches, 'id', 'name', '', 'اختر الفرع الوجهة...');
        },

        async submitStockTransfer(event) {
            if (event) event.preventDefault();
            const productKey = (document.getElementById('trf-product-id') || {}).value || '';
            const parts = String(productKey).split('|');
            const branchSelect = document.getElementById('trf-branch-id');
            const qtyEl = document.getElementById('trf-qty');
            const notesEl = document.getElementById('trf-notes');
            const toBranchId = branchSelect ? branchSelect.value : '';
            const quantity = this.num(qtyEl ? qtyEl.value : 0);
            if (!parts[0] || !toBranchId) {
                this.toast('يرجى اختيار المنتج والفرع الوجهة.', 'warning');
                return false;
            }
            if (!quantity || quantity < 1) {
                this.toast('يرجى إدخال كمية صحيحة أكبر من صفر.', 'warning');
                return false;
            }
            const result = await this.apiPost('inventory.transfer', {
                product_id: Number(parts[0]),
                variant_id: parts[1] ? Number(parts[1]) : null,
                to_branch_id: Number(toBranchId),
                quantity: quantity,
                notes: notesEl ? notesEl.value.trim() : ''
            });
            if (!result) return false;
            this.toast('تم تحويل ' + this.num(result.quantity) + ' من ' + (result.product_name || '') + ' إلى ' + (result.to_branch_name || '') + '.', 'success');
            if (qtyEl) qtyEl.value = '1';
            if (notesEl) notesEl.value = '';
            this.state.allProducts = [];
            this.loadInventory();
            this.loadInventoryTransferOptions();
            return false;
        },

        async loadInventoryMovements() {
            const start = document.getElementById('inv-mov-start');
            const end = document.getElementById('inv-mov-end');
            const type = document.getElementById('inv-mov-type');
            const data = await this.apiGet('inventory.movements', {
                start_date: start ? start.value : '',
                end_date: end ? end.value : '',
                movement_type: type ? type.value : '',
                limit: 400
            });
            if (!data) return;
            const self = this;
            this.renderTable('inventory-movements-table', [
                { label: 'التاريخ', render: function (r) { return self.dateTime(r.created_at); } },
                { label: 'المنتج', render: function (r) { return '<span class="invx-prod-em" aria-hidden="true">' + self.posProductEmoji(r) + '</span>' + self.escapeHtml(r.product_name) + (r.variant_name ? ' - ' + self.escapeHtml(r.variant_name) : ''); } },
                { label: 'النوع', render: function (r) { return self.statusLabel(r.movement_type); } },
                { label: 'قبل', render: function (r) { return self.num(r.quantity_before); } },
                { label: 'التغيير', render: function (r) { return (self.num(r.quantity_change) > 0 ? '+' : '') + self.num(r.quantity_change); } },
                { label: 'بعد', render: function (r) { return self.num(r.quantity_after); } },
                { label: 'المرجع', render: function (r) { return self.escapeHtml(r.invoice_number || r.reference_type || ''); } },
                { label: 'المستخدم', render: function (r) { return self.escapeHtml(r.user_name || ''); } }
            ], data.movements || [], 'لا توجد حركات مخزون في الفترة المحددة.');
        },

        stockAdjustAllowed() {
            const boot = this.boot() || {};
            return boot.allowStockAdjustments !== false && this.has('inventory.adjust');
        },

        openInventoryAdjustFor(productId, variantId) {
            if (!this.stockAdjustAllowed()) {
                this.toast('تسويات المخزون معطلة من إعدادات الصيدلية.', 'warning');
                return;
            }
            this.navigate('inventory');
            setTimeout(function () {
                const tabBtn = document.querySelector('[data-tab-group="inventory"][data-tab="adjust"]');
                if (tabBtn) tabBtn.click();
                const select = document.getElementById('adj-product-id');
                if (select) {
                    select.value = String(productId) + '|' + String(variantId || '');
                    select.dispatchEvent(new Event('change'));
                }
            }.bind(this), 120);
        },

        openInventoryLowTab() {
            this.navigate('inventory');
            setTimeout(function () {
                const tabBtn = document.querySelector('[data-tab-group="inventory"][data-tab="low"]');
                if (tabBtn) tabBtn.click();
            }, 120);
        },

        openPharmacyForecastTab() {
            if (!this.perm('pharmacy')) {
                this.toast('لا تملك صلاحية الوصول إلى التوقعات.', 'warning');
                return;
            }
            this.navigate('pharmacy');
            setTimeout(function () {
                const tabBtn = document.querySelector('[data-tab-group="pharmacy"][data-tab="supply"]');
                if (tabBtn) tabBtn.click();
            }, 120);
        },

        openMonthDebts() {
            this.navigate('customers');
        },

        async submitInventoryAdjustment(event) {
            if (event) event.preventDefault();
            if (!this.stockAdjustAllowed()) {
                this.toast('تسويات المخزون معطلة من إعدادات الصيدلية.', 'warning');
                return false;
            }
            const select = document.getElementById('adj-product-id');
            const typeEl = document.getElementById('adj-type');
            const qtyEl = document.getElementById('adj-qty');
            const reasonEl = document.getElementById('adj-reason');
            if (!select || !select.value) { this.toast('اختر المنتج أولًا.', 'warning'); return false; }
            const parts = String(select.value).split('|');
            const type = typeEl ? typeEl.value : 'adjustment';
            const qty = this.num(qtyEl ? qtyEl.value : 0);
            const reason = reasonEl ? reasonEl.value.trim() : '';
            const payload = {
                product_id: Number(parts[0]),
                variant_id: parts[1] ? Number(parts[1]) : null,
                adjustment_type: type,
                reason: reason,
                notes: ''
            };
            if (type === 'stocktake') payload.actual_quantity = qty;
            else if (type === 'damaged' || type === 'lost') payload.quantity = Math.abs(qty);
            else payload.quantity_change = qty;
            const result = await this.apiPost('inventory.adjust', payload);
            if (!result) return false;
            this.toast('تم تسجيل حركة المخزون بنجاح.', 'success');
            if (reasonEl) reasonEl.value = '';
            this.state.allProducts = [];
            this.loadInventory();
            return false;
        },

        /* =================================================================
         * Purchases
         * ================================================================= */
        async loadPurchases() {
            const start = document.getElementById('purchases-start');
            const end = document.getElementById('purchases-end');
            const data = await this.apiGet('purchases.list', {
                start_date: start ? start.value : '',
                end_date: end ? end.value : '',
                limit: 300
            });
            if (!data) return;
            const self = this;
            this.renderTable('purchases-table', [
                { label: 'رقم الفاتورة', render: function (r) { return '<strong>' + self.escapeHtml(r.invoice_number) + '</strong>'; } },
                { label: 'المورد', render: function (r) { return self.escapeHtml(r.supplier_name || ''); } },
                { label: 'التاريخ', render: function (r) { return self.dateOnly(r.purchase_date); } },
                { label: 'الإجمالي', render: function (r) { return '<span class="money">' + self.money(r.total_amount) + '</span>'; } },
                { label: 'المدفوع', render: function (r) { return self.money(r.paid_amount); } },
                { label: 'المتبقي', render: function (r) { return '<span class="' + (self.num(r.remaining_amount) > 0 ? 'danger-text' : 'success-text') + '">' + self.money(r.remaining_amount) + '</span>'; } },
                { label: 'الحالة', render: function (r) { return self.statusBadge(r.status); } },
                { label: 'إجراءات', render: function (r) { return '<button class="btn btn-sm btn-outline" onclick="LeviPOS.openPurchaseDetails(' + r.id + ')">تفاصيل</button>'; } }
            ], data.purchases || [], 'لا توجد فواتير مشتريات في الفترة المحددة.');
        },

        async openPurchaseModal() {
            await this.ensureReferenceData();
            const results = await Promise.all([
                this.apiGet('suppliers.list', { limit: 500 }),
                this.ensureProductCatalog()
            ]);
            if (results[0]) this.state.suppliers = results[0].suppliers || [];
            if (!this.state.suppliers.length) {
                this.toast('أضف موردًا أولًا قبل تسجيل فاتورة مشتريات.', 'warning');
                this.navigate('suppliers');
                return;
            }
            const products = this.state.allProducts || [];
            if (!products.length) {
                this.toast('أضف منتجات أولًا قبل تسجيل فاتورة مشتريات.', 'warning');
                return;
            }
            const supplierOptions = this.options(this.state.suppliers, 'id', 'name', '', 'اختر المورد...');
            const body = ''
                + '<div class="form-grid">'
                + '<div><label>المورد *</label><select id="pur-supplier" class="form-select">' + supplierOptions + '</select></div>'
                + '<div><label>رقم فاتورة المورد</label><input id="pur-supplier-invoice" class="form-control"></div>'
                + '<div><label>تاريخ الشراء</label><input type="date" id="pur-date" class="form-control" value="' + new Date().toISOString().substring(0, 10) + '"></div>'
                + '<div><label>طريقة الدفع</label><select id="pur-method" class="form-select"><option value="cash">نقدي</option><option value="card">بطاقة</option><option value="transfer">تحويل</option><option value="cheque">شيك</option></select></div>'
                + '<div><label>المبلغ المدفوع</label><input type="number" step="0.01" min="0" id="pur-paid" class="form-control" value="0"></div>'
                + '<div><label>العملة</label><input id="pur-currency" class="form-control" value="' + this.escapeHtml(this.boot().baseCurrency || 'LYD') + '"></div>'
                + '<div><label>خصم الفاتورة</label><input type="number" step="0.01" min="0" id="pur-discount" class="form-control" value="0"></div>'
                + '<div><label>مصاريف شحن</label><input type="number" step="0.01" min="0" id="pur-shipping" class="form-control" value="0"></div>'
                + '<div><label>مصاريف إضافية</label><input type="number" step="0.01" min="0" id="pur-extra" class="form-control" value="0"></div>'
                + '<div><label>توزيع التكلفة الإضافية</label><select id="pur-allocation" class="form-select"><option value="value">حسب قيمة الصنف</option><option value="quantity">حسب الكمية</option></select></div>'
                + '</div>'
                + '<div class="mt-3"><div class="d-flex justify-content-between align-items-center mb-2"><strong style="font-size:13px;">أصناف الفاتورة</strong><button type="button" class="btn btn-sm btn-outline" onclick="LeviPOS.addPurchaseItemRow()">+ صنف</button></div>'
                + '<div id="purchase-items"></div></div>'
                + '<div class="mt-2"><label>ملاحظات</label><textarea id="pur-notes" class="form-control" rows="2"></textarea></div>';
            const footer = '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إلغاء</button>'
                + '<button type="button" class="btn btn-primary" onclick="LeviPOS.submitPurchase()">حفظ الفاتورة</button>';
            this.openModal('فاتورة مشتريات جديدة', body, footer, 'wide');
            this.addPurchaseItemRow();
        },

        addPurchaseItemRow() {
            const wrap = document.getElementById('purchase-items');
            if (!wrap) return;
            const products = this.state.allProducts || [];
            const row = document.createElement('div');
            row.className = 'purchase-item-row';
            row.style.cssText = 'display:grid;grid-template-columns:2fr .6fr .8fr .7fr 1fr 1fr 1fr auto;gap:6px;margin-bottom:6px;align-items:end;';
            row.innerHTML = '<div><label style="font-size:10.5px;">الدواء</label><select class="form-select pi-product">' + this.productOptionHtml(products, '') + '</select></div>'
                + '<div><label style="font-size:10.5px;">الكمية</label><input type="number" min="1" value="1" class="form-control pi-qty"></div>'
                + '<div><label style="font-size:10.5px;">سعر التكلفة</label><input type="number" step="0.01" min="0" value="0" class="form-control pi-cost"></div>'
                + '<div><label style="font-size:10.5px;">خصم السطر</label><input type="number" step="0.01" min="0" value="0" class="form-control pi-discount"></div>'
                + '<div><label style="font-size:10.5px;">رقم الدفعة</label><input class="form-control pi-batch" maxlength="120"></div>'
                + '<div><label style="font-size:10.5px;">التصنيع</label><input type="date" class="form-control pi-manufacturing"></div>'
                + '<div><label style="font-size:10.5px;">الانتهاء</label><input type="date" class="form-control pi-expiry"></div>'
                + '<div><button type="button" class="btn btn-sm btn-danger" onclick="this.closest(\'.purchase-item-row\').remove()">×</button></div>';
            wrap.appendChild(row);
            const select = row.querySelector('.pi-product');
            select.addEventListener('change', function () {
                const product = this.findProductByKey(select.value);
                if (product) {
                    row.querySelector('.pi-cost').value = this.num(product.purchase_cost).toFixed(2);
                }
            }.bind(this));
            if (select.options.length > 1) {
                select.selectedIndex = 1;
                select.dispatchEvent(new Event('change'));
            }
        },

        async submitPurchase() {
            const supplier = document.getElementById('pur-supplier');
            if (!supplier || !supplier.value) { this.toast('اختر المورد.', 'warning'); return; }
            const items = [];
            document.querySelectorAll('.purchase-item-row').forEach(function (row) {
                const key = row.querySelector('.pi-product').value;
                if (!key) return;
                const parts = key.split('|');
                const qty = this.num(row.querySelector('.pi-qty').value);
                if (qty <= 0) return;
                items.push({
                    product_id: Number(parts[0]),
                    variant_id: parts[1] ? Number(parts[1]) : null,
                    quantity: qty,
                    unit_cost: this.num(row.querySelector('.pi-cost').value),
                    discount_amount: this.num(row.querySelector('.pi-discount').value),
                    batch_number: row.querySelector('.pi-batch').value.trim(),
                    manufacturing_date: row.querySelector('.pi-manufacturing').value,
                    expiry_date: row.querySelector('.pi-expiry').value
                });
            }, this);
            if (!items.length) { this.toast('أضف صنفًا واحدًا على الأقل.', 'warning'); return; }
            const payload = {
                supplier_id: supplier.value,
                supplier_invoice_no: document.getElementById('pur-supplier-invoice').value.trim(),
                purchase_date: document.getElementById('pur-date').value,
                payment_method: document.getElementById('pur-method').value,
                paid_amount: document.getElementById('pur-paid').value || 0,
                currency_code: document.getElementById('pur-currency').value.trim() || (this.boot().baseCurrency || 'LYD'),
                discount_amount: document.getElementById('pur-discount').value || 0,
                shipping_cost: document.getElementById('pur-shipping').value || 0,
                extra_cost: document.getElementById('pur-extra').value || 0,
                cost_allocation_method: document.getElementById('pur-allocation').value,
                notes: document.getElementById('pur-notes').value.trim(),
                items: items
            };
            const result = await this.apiPost('purchases.create', payload);
            if (!result) return;
            this.toast('تم حفظ فاتورة المشتريات وتحديث المخزون.', 'success');
            this.closeModal();
            this.state.allProducts = [];
            this.state.posMetaLoaded = false;
            this.loadPurchases();
        },

        async openPurchaseDetails(id) {
            const purchase = await this.apiGet('purchases.get', { purchase_id: id });
            if (!purchase) return;
            const self = this;
            let html = '<div class="stat-grid" style="margin-bottom:12px;">'
                + this.statCard('الإجمالي', this.money(purchase.total_amount), 'blue')
                + this.statCard('المدفوع', this.money(purchase.paid_amount), 'green')
                + this.statCard('المتبقي', this.money(purchase.remaining_amount), this.num(purchase.remaining_amount) > 0 ? 'red' : 'green')
                + this.statCard('الحالة', this.statusBadge(purchase.status), 'gray')
                + '</div>';
            html += '<div class="muted mb-2">المورد: ' + this.escapeHtml(purchase.supplier_name || '') + ' • التاريخ: ' + this.dateOnly(purchase.purchase_date) + '</div>';
            html += '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>المنتج</th><th>الكمية</th><th>المرتجع</th><th>التكلفة</th><th>الإجمالي</th></tr></thead><tbody>';
            (purchase.items || []).forEach(function (item) {
                html += '<tr><td>' + self.escapeHtml(item.product_name) + (item.variant_name ? ' - ' + self.escapeHtml(item.variant_name) : '') + '</td>'
                    + '<td>' + self.num(item.quantity) + '</td><td>' + self.num(item.returned_quantity) + '</td>'
                    + '<td>' + self.money(item.landed_unit_cost) + '</td><td>' + self.money(item.line_total) + '</td></tr>';
            });
            html += '</tbody></table></div>';
            let footer = '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إغلاق</button>';
            if (purchase.status !== 'cancelled') {
                if (this.has('purchases.return')) {
                    footer += '<button type="button" class="btn btn-warning" onclick="LeviPOS.openPurchaseReturnModal(' + purchase.id + ')">مرتجع مشتريات</button>';
                }
                footer += '<button type="button" class="btn btn-danger" onclick="LeviPOS.cancelPurchase(' + purchase.id + ')">إلغاء الفاتورة</button>';
            }
            this.openModal('تفاصيل فاتورة الشراء ' + (purchase.invoice_number || ''), html, footer, 'wide');
        },

        async cancelPurchase(id) {
            const reason = window.prompt('سبب إلغاء فاتورة الشراء (اختياري):');
            if (reason === null) return;
            if (!window.confirm('سيتم عكس المخزون والرصيد والدفعات النقدية. هل تريد المتابعة؟')) return;
            const result = await this.apiPost('purchases.cancel', { purchase_id: id, reason: reason.trim() });
            if (!result) return;
            this.toast('تم إلغاء فاتورة الشراء.', 'success');
            this.closeModal();
            this.state.allProducts = [];
            this.loadPurchases();
        },

        async openPurchaseReturnModal(id) {
            const purchase = await this.apiGet('purchases.get', { purchase_id: id });
            if (!purchase) return;
            const self = this;
            let html = '<div class="muted mb-2">حدد الكميات المرتجعة. المتاح للإرجاع لكل صنف = الكمية الأصلية − المرتجع سابقًا.</div>';
            html += '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>المنتج</th><th>المتاح</th><th>كمية الإرجاع</th></tr></thead><tbody>';
            (purchase.items || []).forEach(function (item) {
                const available = self.num(item.quantity) - self.num(item.returned_quantity);
                html += '<tr><td>' + self.escapeHtml(item.product_name) + (item.variant_name ? ' - ' + self.escapeHtml(item.variant_name) : '') + '</td>'
                    + '<td>' + available + '</td>'
                    + '<td><input type="number" min="0" max="' + available + '" value="0" class="form-control pr-qty" data-item-id="' + item.id + '" style="width:90px;"></td></tr>';
            });
            html += '</tbody></table></div>';
            html += '<div class="form-grid mt-3"><div><label>سبب الإرجاع (اختياري)</label><input id="pr-reason" class="form-control" placeholder="سبب الإرجاع (اختياري)"></div>'
                + '<div><label>طريقة التسوية</label><select id="pr-method" class="form-select"><option value="balance_deduction">خصم من رصيد المورد</option><option value="cash_refund">استرداد نقدي</option></select></div></div>';
            const footer = '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إلغاء</button>'
                + '<button type="button" class="btn btn-primary" onclick="LeviPOS.submitPurchaseReturn(' + id + ')">حفظ المرتجع</button>';
            this.openModal('مرتجع مشتريات', html, footer, 'wide');
        },

        async submitPurchaseReturn(id) {
            const reason = document.getElementById('pr-reason').value.trim();
            const items = [];
            document.querySelectorAll('.pr-qty').forEach(function (input) {
                const qty = this.num(input.value);
                if (qty > 0) items.push({ purchase_item_id: Number(input.getAttribute('data-item-id')), quantity: qty });
            }, this);
            if (!items.length) { this.toast('أدخل كمية إرجاع لصنف واحد على الأقل.', 'warning'); return; }
            const result = await this.apiPost('purchases.return_create', {
                purchase_id: id,
                reason: reason,
                refund_method: document.getElementById('pr-method').value,
                items: items
            });
            if (!result) return;
            this.toast('تم تسجيل مرتجع المشتريات.', 'success');
            this.closeModal();
            this.state.allProducts = [];
            this.loadPurchases();
        },

        /* =================================================================
         * Suppliers
         * ================================================================= */
        async loadSuppliers() {
            const search = document.getElementById('suppliers-search');
            const q = search ? search.value.trim() : '';
            const data = await this.apiGet('suppliers.list', { q: q, limit: 300 });
            if (!data) return;
            this.state.suppliers = data.suppliers || [];
            const self = this;
            this.renderTable('suppliers-table', [
                { label: 'المورد', render: function (r) { return '<strong>' + self.escapeHtml(r.name) + '</strong>' + (r.company_name ? '<br><span class="muted">' + self.escapeHtml(r.company_name) + '</span>' : ''); } },
                { label: 'الهاتف', render: function (r) { return self.escapeHtml(r.phone || ''); } },
                { label: 'الرصيد', render: function (r) { return '<span class="' + (self.num(r.current_balance) > 0 ? 'danger-text' : self.num(r.current_balance) < 0 ? 'success-text' : 'muted') + '">' + self.money(r.current_balance) + '</span>'; } },
                { label: 'المشتريات', render: function (r) { return self.num(r.purchase_count); } },
                { label: 'آخر شراء', render: function (r) { return r.last_purchase ? self.dateOnly(r.last_purchase) : '-'; } },
                { label: 'الحالة', render: function (r) { return self.badge(Number(r.is_active) === 1 ? 'نشط' : 'معطل', Number(r.is_active) === 1 ? 'green' : 'red'); } },
                { label: 'إجراءات', render: function (r) {
                    return '<div style="display:flex;gap:4px;flex-wrap:wrap;">'
                        + '<button class="btn btn-sm btn-outline" onclick="LeviPOS.openSupplierStatement(' + r.id + ')">كشف</button>'
                        + '<button class="btn btn-sm btn-success" onclick="LeviPOS.openSupplierPayModal(' + r.id + ')">دفعة</button>'
                        + '<button class="btn btn-sm btn-outline" onclick="LeviPOS.openSupplierModal(' + r.id + ')">تعديل</button>'
                        + '<button class="btn btn-sm btn-danger" onclick="LeviPOS.deleteSupplier(' + r.id + ')">حذف</button>'
                        + '</div>';
                } }
            ], this.state.suppliers, 'لا يوجد موردون. أضف موردًا جديدًا.');
        },

        async openSupplierModal(id) {
            const supplier = id ? await this.apiGet('suppliers.get', { supplier_id: id }) : null;
            if (id && !supplier) return;
            const val = function (key, fallback) {
                return this.escapeHtml(supplier && supplier[key] != null ? supplier[key] : (fallback == null ? '' : fallback));
            }.bind(this);
            const body = '<div class="form-grid">'
                + '<div><label>اسم المورد *</label><input id="sf-name" class="form-control" value="' + val('name') + '"></div>'
                + '<div><label>اسم الشركة</label><input id="sf-company" class="form-control" value="' + val('company_name') + '"></div>'
                + '<div><label>الهاتف</label><input id="sf-phone" class="form-control" value="' + val('phone') + '"></div>'
                + '<div><label>البريد الإلكتروني</label><input type="email" id="sf-email" class="form-control" value="' + val('email') + '"></div>'
                + '<div><label>العنوان</label><input id="sf-address" class="form-control" value="' + val('address') + '"></div>'
                + (id ? '' : '<div><label>الرصيد الافتتاحي</label><input type="number" step="0.01" id="sf-opening" class="form-control" value="0"></div>')
                + '<div><label>ملاحظات</label><textarea id="sf-notes" class="form-control" rows="2">' + val('notes') + '</textarea></div>'
                + '<div><label><input type="checkbox" id="sf-active" ' + (!id || Number(supplier.is_active) === 1 ? 'checked' : '') + '> نشط</label></div>'
                + '</div>';
            const footer = '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إلغاء</button>'
                + '<button type="button" class="btn btn-primary" onclick="LeviPOS.submitSupplier(' + (id || 'null') + ')">حفظ</button>';
            this.openModal(id ? 'تعديل المورد' : 'إضافة مورد', body, footer);
        },

        async submitSupplier(id) {
            const value = function (fieldId) { const el = document.getElementById(fieldId); return el ? el.value.trim() : ''; };
            const name = value('sf-name');
            if (!name) { this.toast('اسم المورد مطلوب.', 'warning'); return; }
            const payload = {
                name: name,
                company_name: value('sf-company'),
                phone: value('sf-phone'),
                email: value('sf-email'),
                address: value('sf-address'),
                notes: value('sf-notes'),
                is_active: document.getElementById('sf-active') && document.getElementById('sf-active').checked ? 1 : 0
            };
            if (id) payload.id = id;
            else payload.opening_balance = value('sf-opening') || 0;
            const result = await this.apiPost('suppliers.save', payload);
            if (!result) return;
            this.toast('تم حفظ بيانات المورد.', 'success');
            this.closeModal();
            this.loadSuppliers();
        },

        async deleteSupplier(id) {
            if (!window.confirm('هل تريد حذف/أرشفة هذا المورد؟')) return;
            const result = await this.apiPost('suppliers.delete', { supplier_id: id });
            if (!result) return;
            this.toast('تمت العملية.', 'success');
            this.loadSuppliers();
        },

        async openSupplierPayModal(id) {
            const supplier = await this.apiGet('suppliers.get', { supplier_id: id });
            if (!supplier) return;
            const body = '<div class="muted mb-2">المورد: <strong>' + this.escapeHtml(supplier.name) + '</strong> • الرصيد الحالي: ' + this.money(supplier.current_balance) + '</div>'
                + '<div class="form-grid">'
                + '<div><label>المبلغ *</label><input type="number" step="0.01" min="0" id="sp-amount" class="form-control" value="0"></div>'
                + '<div><label>العملة</label><input id="sp-currency" class="form-control" value="' + this.escapeHtml(this.boot().baseCurrency || 'LYD') + '"></div>'
                + '<div><label>طريقة الدفع</label><select id="sp-method" class="form-select"><option value="cash">نقدي</option><option value="card">بطاقة</option><option value="transfer">تحويل مصرفي</option><option value="cheque">شيك</option></select></div>'
                + '<div><label>ملاحظات</label><input id="sp-notes" class="form-control"></div>'
                + '</div>';
            const footer = '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إلغاء</button>'
                + '<button type="button" class="btn btn-success" onclick="LeviPOS.submitSupplierPay(' + id + ')">تسجيل الدفعة</button>';
            this.openModal('دفعة لمورد', body, footer);
        },

        async submitSupplierPay(id) {
            const amount = this.num(document.getElementById('sp-amount').value);
            if (amount <= 0) { this.toast('أدخل مبلغًا صحيحًا.', 'warning'); return; }
            const result = await this.apiPost('suppliers.pay', {
                supplier_id: id,
                amount: amount,
                currency_code: document.getElementById('sp-currency').value.trim() || (this.boot().baseCurrency || 'LYD'),
                payment_method: document.getElementById('sp-method').value,
                notes: document.getElementById('sp-notes').value.trim()
            });
            if (!result) return;
            this.toast('تم تسجيل الدفعة.', 'success');
            this.closeModal();
            this.loadSuppliers();
        },

        async openSupplierStatement(id) {
            const today = new Date().toISOString().substring(0, 10);
            const data = await this.apiGet('suppliers.statement', {
                supplier_id: id, start_date: '2000-01-01', end_date: today
            });
            if (!data) return;
            const self = this;
            let html = '<div class="muted mb-2">المورد: <strong>' + this.escapeHtml(data.supplier.name) + '</strong> • الرصيد الختامي: <strong>' + this.money(data.closing_balance) + '</strong></div>';
            html += '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>التاريخ</th><th>المرجع</th><th>النوع</th><th>مدين</th><th>دائن</th></tr></thead><tbody>';
            (data.entries || []).forEach(function (entry) {
                html += '<tr><td>' + self.dateTime(entry.entry_date) + '</td><td>' + self.escapeHtml(entry.reference || '') + '</td><td>' + self.statementTypeLabel(entry.entry_type) + '</td><td>' + self.money(entry.debit) + '</td><td>' + self.money(entry.credit) + '</td></tr>';
            });
            html += '</tbody></table></div>';
            this.openModal('كشف حساب المورد', html, '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إغلاق</button>', 'wide');
        },

        /* =================================================================
         * Customers
         * ================================================================= */
        async loadCustomers() {
            const search = document.getElementById('customers-search');
            const q = search ? search.value.trim() : '';
            const data = await this.apiGet('customers.list', { q: q, limit: 300 });
            if (!data) return;
            this.state.customers = data.customers || [];
            const self = this;
            this.renderTable('customers-table', [
                { label: 'العميل', render: function (r) { return '<strong>' + self.escapeHtml(r.name) + '</strong>'; } },
                { label: 'الهاتف', render: function (r) { return self.escapeHtml(r.phone || ''); } },
                { label: 'الدين الحالي', render: function (r) { return '<span class="' + (self.num(r.current_debt) > 0 ? 'danger-text' : 'success-text') + '">' + self.money(r.current_debt) + '</span>'; } },
                { label: 'حد الائتمان', render: function (r) { return self.money(r.credit_limit); } },
                { label: 'الفواتير', render: function (r) { return self.num(r.invoice_count); } },
                { label: 'آخر شراء', render: function (r) { return r.last_sale ? self.dateOnly(r.last_sale) : '-'; } },
                { label: 'إجراءات', render: function (r) {
                    return '<div style="display:flex;gap:4px;flex-wrap:wrap;">'
                        + '<button class="btn btn-sm btn-outline" onclick="LeviPOS.openCustomerStatement(' + r.id + ')">كشف</button>'
                        + '<button class="btn btn-sm btn-success" onclick="LeviPOS.openCustomerCollectModal(' + r.id + ')">تحصيل</button>'
                        + '<button class="btn btn-sm btn-outline" onclick="LeviPOS.openCustomerModal(' + r.id + ')">تعديل</button>'
                        + '<button class="btn btn-sm btn-danger" onclick="LeviPOS.deleteCustomer(' + r.id + ')">حذف</button>'
                        + '</div>';
                } }
            ], this.state.customers, 'لا يوجد عملاء. أضف عميلًا جديدًا.');
        },

        async openCustomerModal(id) {
            const fromPos = this.state.section === 'pos';
            const customer = id ? await this.apiGet('customers.get', { customer_id: id }) : null;
            if (id && !customer) return;
            const val = function (key, fallback) {
                return this.escapeHtml(customer && customer[key] != null ? customer[key] : (fallback == null ? '' : fallback));
            }.bind(this);
            const genderOptions = [
                { v: '', l: 'غير محدد' }, { v: 'male', l: 'ذكر' }, { v: 'female', l: 'أنثى' }, { v: 'other', l: 'أخرى' }
            ];
            const body = '<div class="form-grid">'
                + '<div><label>اسم المريض / العميل *</label><input id="cf-name" class="form-control" value="' + val('name') + '"></div>'
                + '<div><label>الهاتف *</label><input id="cf-phone" class="form-control" value="' + val('phone') + '"></div>'
                + '<div><label>البريد الإلكتروني</label><input type="email" id="cf-email" class="form-control" value="' + val('email') + '"></div>'
                + '<div><label>تاريخ الميلاد</label><input type="date" id="cf-birth" class="form-control" value="' + val('birth_date') + '"></div>'
                + '<div><label>النوع</label><select id="cf-gender" class="form-select">' + this.options(genderOptions, 'v', 'l', customer ? customer.gender : '', '') + '</select></div>'
                + '<div><label>فصيلة الدم</label><input id="cf-blood" class="form-control" maxlength="10" value="' + val('blood_type') + '"></div>'
                + '<div><label>الحساسية</label><input id="cf-allergies" class="form-control" value="' + val('allergies') + '"></div>'
                + '<div><label>حد الائتمان</label><input type="number" step="0.01" min="0" id="cf-limit" class="form-control" value="' + val('credit_limit', '0') + '"></div>'
                + '<div><label>العنوان</label><input id="cf-address" class="form-control" value="' + val('address') + '"></div>'
                + '<div style="grid-column:1/-1;"><label>ملاحظات طبية</label><textarea id="cf-medical" class="form-control" rows="2">' + val('medical_notes') + '</textarea></div>'
                + '<div><label>ملاحظات عامة</label><textarea id="cf-notes" class="form-control" rows="2">' + val('notes') + '</textarea></div>'
                + '<div><label><input type="checkbox" id="cf-active" ' + (!id || Number(customer.is_active) === 1 ? 'checked' : '') + '> نشط</label></div>'
                + '</div>';
            const footer = '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إلغاء</button>'
                + '<button type="button" class="btn btn-primary" onclick="LeviPOS.submitCustomer(' + (id || 'null') + ',' + (fromPos ? 'true' : 'false') + ')">حفظ</button>';
            this.openModal(id ? 'تعديل ملف المريض' : 'إضافة مريض / عميل', body, footer);
        },

        async submitCustomer(id, fromPos) {
            const value = function (fieldId) { const el = document.getElementById(fieldId); return el ? el.value.trim() : ''; };
            const name = value('cf-name');
            const phone = value('cf-phone');
            if (!name || !phone) { this.toast('اسم المريض والهاتف مطلوبان.', 'warning'); return; }
            const payload = {
                name: name,
                phone: phone,
                email: value('cf-email'),
                address: value('cf-address'),
                birth_date: value('cf-birth') || null,
                gender: value('cf-gender') || null,
                blood_type: value('cf-blood') || null,
                allergies: value('cf-allergies'),
                medical_notes: value('cf-medical'),
                notes: value('cf-notes'),
                credit_limit: value('cf-limit') || 0,
                is_active: document.getElementById('cf-active') && document.getElementById('cf-active').checked ? 1 : 0
            };
            if (id) payload.id = id;
            const result = await this.apiPost('customers.save', payload);
            if (!result) return;
            this.toast('تم حفظ بيانات العميل.', 'success');
            this.closeModal();
            if (fromPos) {
                const customerId = result.customer_id || result.id || id;
                this.state.customer = { id: customerId, name: name, phone: phone };
                const hidden = document.getElementById('pos-customer-id');
                const input = document.getElementById('pos-customer-search');
                if (hidden) hidden.value = customerId;
                if (input) input.value = name + ' - ' + phone;
            }
            this.loadCustomers();
        },

        async deleteCustomer(id) {
            if (!window.confirm('هل تريد حذف/أرشفة هذا العميل؟')) return;
            const result = await this.apiPost('customers.delete', { customer_id: id });
            if (!result) return;
            this.toast('تمت العملية.', 'success');
            this.loadCustomers();
        },

        async openCustomerCollectModal(id) {
            const customer = await this.apiGet('customers.get', { customer_id: id });
            if (!customer) return;
            const body = '<div class="muted mb-2">العميل: <strong>' + this.escapeHtml(customer.name) + '</strong> • الدين الحالي: ' + this.money(customer.current_debt) + '</div>'
                + '<div class="form-grid">'
                + '<div><label>المبلغ المحصل *</label><input type="number" step="0.01" min="0" id="cc-amount" class="form-control" value="0"></div>'
                + '<div><label>العملة</label><input id="cc-currency" class="form-control" value="' + this.escapeHtml(this.boot().baseCurrency || 'LYD') + '"></div>'
                + '<div><label>طريقة الدفع</label><select id="cc-method" class="form-select"><option value="cash">نقدي</option><option value="card">بطاقة</option><option value="transfer">تحويل</option><option value="cheque">شيك</option></select></div>'
                + '<div><label>ملاحظات</label><input id="cc-notes" class="form-control"></div>'
                + '</div>';
            const footer = '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إلغاء</button>'
                + '<button type="button" class="btn btn-success" onclick="LeviPOS.submitCustomerCollect(' + id + ')">تسجيل التحصيل</button>';
            this.openModal('تحصيل دين عميل', body, footer);
        },

        async submitCustomerCollect(id) {
            const amount = this.num(document.getElementById('cc-amount').value);
            if (amount <= 0) { this.toast('أدخل مبلغًا صحيحًا.', 'warning'); return; }
            const result = await this.apiPost('customers.collect_payment', {
                customer_id: id,
                amount: amount,
                currency_code: document.getElementById('cc-currency').value.trim() || (this.boot().baseCurrency || 'LYD'),
                payment_method: document.getElementById('cc-method').value,
                notes: document.getElementById('cc-notes').value.trim()
            });
            if (!result) return;
            this.toast('تم تسجيل التحصيل.', 'success');
            this.closeModal();
            this.loadCustomers();
        },

        async openCustomerStatement(id) {
            const today = new Date().toISOString().substring(0, 10);
            const data = await this.apiGet('customers.statement', {
                customer_id: id, start_date: '2000-01-01', end_date: today
            });
            if (!data) return;
            const self = this;
            let html = '<div class="muted mb-2">العميل: <strong>' + this.escapeHtml(data.customer.name) + '</strong> • الدين الختامي: <strong>' + this.money(data.closing_debt) + '</strong></div>';
            html += '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>التاريخ</th><th>المرجع</th><th>النوع</th><th>الإجمالي</th><th>المدفوع</th><th>المتبقي على العميل</th></tr></thead><tbody>';
            (data.entries || []).forEach(function (entry) {
                const remaining = entry.entry_type === 'sale'
                    ? '<span class="' + (self.num(entry.remaining_debt) > 0 ? 'danger-text' : 'success-text') + '">' + self.money(entry.remaining_debt) + '</span>'
                    : '<span class="muted">-</span>';
                html += '<tr><td>' + self.dateTime(entry.entry_date) + '</td><td>' + self.escapeHtml(entry.reference || '') + '</td><td>' + self.statementTypeLabel(entry.entry_type) + '</td><td>' + self.money(entry.debit) + '</td><td>' + self.money(entry.credit) + '</td><td>' + remaining + '</td></tr>';
            });
            html += '</tbody></table></div>';
            this.openModal('كشف حساب العميل', html, '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إغلاق</button>', 'wide');
        },

        /* =================================================================
         * Invoices
         * ================================================================= */
        async loadInvoices() {
            const start = document.getElementById('invoices-start');
            const end = document.getElementById('invoices-end');
            const status = document.getElementById('invoices-status');
            const q = document.getElementById('invoices-q');
            const data = await this.apiGet('sales.list', {
                start_date: start ? start.value : '',
                end_date: end ? end.value : '',
                status: status ? status.value : '',
                q: q ? q.value.trim() : '',
                limit: 400
            });
            if (!data) return;
            const self = this;
            const columns = [
                { label: 'الفاتورة', render: function (r) { return '<strong>' + self.escapeHtml(r.invoice_number) + '</strong>'; } },
                { label: 'التاريخ', render: function (r) { return self.dateTime(r.sale_date); } },
                { label: 'العميل', render: function (r) { return self.escapeHtml(r.customer_name || 'عميل نقدي'); } },
                { label: 'الأصناف', render: function (r) { return self.num(r.items_count); } },
                { label: 'الإجمالي', render: function (r) { return '<span class="money">' + self.money(r.total_amount) + '</span>'; } },
                { label: 'المدفوع', render: function (r) { return self.money(r.paid_amount); } },
                { label: 'المتبقي', render: function (r) { return '<span class="' + (self.num(r.remaining_debt) > 0 ? 'danger-text' : 'success-text') + '">' + self.money(r.remaining_debt) + '</span>'; } },
                { label: 'الحالة', render: function (r) { return self.statusBadge(r.status); } },
                { label: 'الدفع', render: function (r) { return self.statusBadge(r.payment_status); } },
                { label: 'إجراءات', render: function (r) {
                    return '<div class="table-actions">'
                        + '<button class="btn btn-sm btn-outline" onclick="LeviPOS.openInvoiceDetails(' + r.id + ')">تفاصيل</button>'
                        + '<button class="btn btn-sm btn-outline" onclick="LeviPOS.printInvoice(' + r.id + ')">طباعة</button>'
                        + (self.has('pos.cancel_invoice') && r.status !== 'cancelled' ? '<button class="btn btn-sm btn-danger" onclick="LeviPOS.cancelInvoice(' + r.id + ')">إلغاء</button>' : '')
                        + '</div>';
                } }
            ];
            if (this.boot().isOwner) {
                columns.unshift({
                    label: '',
                    labelHtml: '<input type="checkbox" title="تحديد الكل" data-check-all="invoice" onchange="LeviPOS.toggleAllTableChecks(\'invoice\', this.checked)">',
                    render: function (r) {
                        return '<input type="checkbox" class="row-check" data-check="invoice" value="' + self.num(r.id) + '">';
                    }
                });
            }
            const rows = data.sales || [];
            this.renderTable('invoices-table', columns, rows, 'لا توجد فواتير في الفترة المحددة.');

            const countEl = document.getElementById('invoices-count');
            if (countEl) {
                countEl.textContent = rows.length
                    ? this.arabicCount(rows.length, {
                        one: 'فاتورة واحدة', two: 'فاتورتان', few: 'فواتير', many: 'فاتورة'
                    })
                    : 'لا فواتير';
            }
        },

        async openInvoiceDetails(id) {
            const sale = await this.apiGet('sales.get', { sale_id: id });
            if (!sale) return;
            const self = this;
            let html = '<div class="stat-grid" style="margin-bottom:12px;">'
                + this.statCard('الإجمالي', this.money(sale.total_amount), 'blue')
                + this.statCard('المدفوع', this.money(sale.paid_amount), 'green')
                + this.statCard('المتبقي', this.money(sale.remaining_debt), this.num(sale.remaining_debt) > 0 ? 'red' : 'green')
                + this.statCard('الحالة', this.statusBadge(sale.status), 'gray')
                + '</div>';
            html += '<div class="muted mb-2">العميل: ' + this.escapeHtml(sale.customer_name || 'عميل نقدي') + ' • الكاشير: ' + this.escapeHtml(sale.cashier_name || '') + ' • التاريخ: ' + this.dateTime(sale.sale_date) + '</div>';
            html += '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>المنتج</th><th>SKU</th><th>الكمية</th><th>المرتجع</th><th>السعر</th><th>الخصم</th><th>الإجمالي</th></tr></thead><tbody>';
            (sale.items || []).forEach(function (item) {
                html += '<tr><td>' + self.escapeHtml(item.product_name_snapshot) + (item.variant_snapshot ? ' - ' + self.escapeHtml(item.variant_snapshot) : '') + '</td>'
                    + '<td>' + self.escapeHtml(item.sku_snapshot || '') + '</td>'
                    + '<td>' + self.num(item.quantity) + '</td><td>' + self.num(item.returned_quantity) + '</td>'
                    + '<td>' + self.money(item.unit_price) + '</td>'
                    + '<td>' + self.money(item.discount_amount) + '</td>'
                    + '<td>' + self.money(item.net_total) + '</td></tr>';
            });
            html += '</tbody></table></div>';
            if ((sale.payments || []).length) {
                html += '<h4 style="font-size:13px;margin-top:12px;">الدفعات</h4><div class="table-responsive"><table class="table table-sm"><thead><tr><th>الطريقة</th><th>العملة</th><th>المبلغ</th><th>المرجع</th></tr></thead><tbody>';
                (sale.payments || []).forEach(function (p) {
                    html += '<tr><td>' + self.statusLabel(p.payment_method) + '</td><td>' + self.escapeHtml(p.currency_code) + '</td><td>' + self.money(p.amount_base) + '</td><td>' + self.escapeHtml(p.reference_no || '') + '</td></tr>';
                });
                html += '</tbody></table></div>';
            }
            if ((sale.returns || []).length) {
                html += '<h4 style="font-size:13px;margin-top:12px;">المرتجعات</h4><div class="table-responsive"><table class="table table-sm"><thead><tr><th>المرتجع</th><th>التاريخ</th><th>المبلغ</th><th>السبب</th></tr></thead><tbody>';
                (sale.returns || []).forEach(function (r) {
                    html += '<tr><td>' + self.escapeHtml(r.return_number) + '</td><td>' + self.dateTime(r.created_at) + '</td><td>' + self.money(r.total_refund_amount) + '</td><td>' + self.escapeHtml(r.reason || '') + '</td></tr>';
                });
                html += '</tbody></table></div>';
            }
            let footer = '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إغلاق</button>'
                + '<button type="button" class="btn btn-outline" onclick="LeviPOS.printInvoice(' + sale.id + ')">طباعة الإيصال</button>';
            if (this.has('returns.create') && sale.status !== 'cancelled') {
                footer += '<button type="button" class="btn btn-warning" onclick="LeviPOS.closeModal(); LeviPOS.navigate(\'returns\'); document.getElementById(\'return-invoice-search\').value=\'' + this.escapeHtml(sale.invoice_number) + '\'; LeviPOS.searchReturnInvoice();">إنشاء مرتجع</button>';
            }
            if (this.has('pos.cancel_invoice') && sale.status !== 'cancelled') {
                footer += '<button type="button" class="btn btn-danger" onclick="LeviPOS.cancelInvoice(' + sale.id + ')">إلغاء الفاتورة</button>';
            }
            this.openModal('تفاصيل الفاتورة ' + (sale.invoice_number || ''), html, footer, 'wide');
        },

        printInvoice(id) {
            window.open('print_receipt.php?sale_id=' + encodeURIComponent(id), '_blank');
        },

        async cancelInvoice(id) {
            const reason = window.prompt('سبب إلغاء الفاتورة (اختياري):');
            if (reason === null) return;
            if (!window.confirm('سيتم عكس المخزون والقيود المالية. هل تريد المتابعة؟')) return;
            const result = await this.apiPost('pos.cancel_invoice', { sale_id: id, reason: reason.trim() });
            if (!result) return;
            this.toast('تم إلغاء الفاتورة وعكس آثارها.', 'success');
            this.closeModal();
            this.loadInvoices();
        },

        /* =================================================================
         * Returns
         * ================================================================= */
        async loadReturns() {
            const today = new Date().toISOString().substring(0, 10);
            const data = await this.apiGet('returns.list', {
                start_date: '2000-01-01', end_date: today
            });
            if (!data) return;
            const self = this;
            const columns = [
                { label: 'المرتجع', render: function (r) { return '<strong>' + self.escapeHtml(r.return_number) + '</strong>'; } },
                { label: 'الفاتورة', render: function (r) { return self.escapeHtml(r.invoice_number || ''); } },
                { label: 'العميل', render: function (r) { return self.escapeHtml(r.customer_name || 'عميل نقدي'); } },
                { label: 'التاريخ', render: function (r) { return self.dateTime(r.created_at); } },
                { label: 'المبلغ', render: function (r) { return '<span class="money">' + self.money(r.total_refund_amount) + '</span>'; } },
                { label: 'طريقة الاسترداد', render: function (r) { return self.statusLabel(r.refund_method); } },
                { label: 'السبب', render: function (r) { return self.escapeHtml(r.reason || ''); } },
                { label: 'المستخدم', render: function (r) { return self.escapeHtml(r.created_by_name || ''); } }
            ];
            if (this.boot().isOwner) {
                columns.unshift({
                    label: '',
                    labelHtml: '<input type="checkbox" title="تحديد الكل" data-check-all="return" onchange="LeviPOS.toggleAllTableChecks(\'return\', this.checked)">',
                    render: function (r) {
                        return '<input type="checkbox" class="row-check" data-check="return" value="' + self.num(r.id) + '">';
                    }
                });
            }
            this.renderTable('returns-table', columns, data.returns || [], 'لا توجد مرتجعات مسجلة.');
        },

        async searchReturnInvoice(silent) {
            const input = document.getElementById('return-invoice-search');
            const q = input ? input.value.trim() : '';
            if (!q) { if (!silent) this.toast('أدخل رقم الفاتورة أو اسم/باركود المنتج.', 'warning'); return; }
            const data = await this.apiGet('returns.search_invoice', { q: q });
            if (!data) return;
            const sales = data.sales || [];
            if (!sales.length) { if (!silent) this.toast('لم يتم العثور على فاتورة مطابقة.', 'warning'); return; }
            if (sales.length === 1) {
                this.openReturnForm(sales[0].id);
                return;
            }
            const self = this;
            let html = '<div class="muted mb-2">تم العثور على ' + sales.length + ' فاتورة. اختر الفاتورة المطلوبة:</div>';
            html += '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>الفاتورة</th><th>التاريخ</th><th>العميل</th><th>الإجمالي</th><th></th></tr></thead><tbody>';
            sales.forEach(function (sale) {
                html += '<tr><td>' + self.escapeHtml(sale.invoice_number) + '</td><td>' + self.dateTime(sale.sale_date) + '</td><td>' + self.escapeHtml(sale.customer_name || 'عميل نقدي') + '</td><td>' + self.money(sale.total_amount) + '</td>'
                    + '<td><button class="btn btn-sm btn-primary" onclick="LeviPOS.closeModal(); LeviPOS.openReturnForm(' + sale.id + ')">اختيار</button></td></tr>';
            });
            html += '</tbody></table></div>';
            this.openModal('نتائج البحث عن الفاتورة', html, '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إغلاق</button>', 'wide');
        },

        async liveSearchReturnInvoice() {
            const input = document.getElementById('return-invoice-search');
            const box = document.getElementById('return-invoice-result');
            if (!input || !box) return;
            const q = input.value.trim();
            if (q.length < 3) {
                box.innerHTML = '';
                return;
            }
            const data = await this.apiGet('returns.search_invoice', { q: q });
            if (!data) return;
            if (input.value.trim() !== q) return;
            const sales = data.sales || [];
            if (!sales.length) {
                box.innerHTML = '<div class="empty-state">لا توجد فاتورة مطابقة.</div>';
                return;
            }
            const self = this;
            let html = '<div class="muted mb-2">تم العثور على ' + sales.length + ' فاتورة. اختر الفاتورة المطلوبة:</div>';
            html += '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>الفاتورة</th><th>التاريخ</th><th>العميل</th><th>الإجمالي</th><th></th></tr></thead><tbody>';
            sales.forEach(function (sale) {
                html += '<tr><td>' + self.escapeHtml(sale.invoice_number) + '</td><td>' + self.dateTime(sale.sale_date) + '</td><td>' + self.escapeHtml(sale.customer_name || 'عميل نقدي') + '</td><td>' + self.money(sale.total_amount) + '</td>'
                    + '<td><button class="btn btn-sm btn-primary" onclick="LeviPOS.openReturnForm(' + sale.id + ')">اختيار</button></td></tr>';
            });
            html += '</tbody></table></div>';
            box.innerHTML = html;
        },

        async openReturnForm(saleId) {
            const sale = await this.apiGet('sales.get', { sale_id: saleId });
            if (!sale) return;
            if (sale.status === 'cancelled') { this.toast('لا يمكن إرجاع فاتورة ملغاة.', 'warning'); return; }
            this.state.returnInvoice = sale;
            const self = this;
            let html = '<div class="muted mb-2">الفاتورة: <strong>' + this.escapeHtml(sale.invoice_number) + '</strong> • العميل: ' + this.escapeHtml(sale.customer_name || 'عميل نقدي') + ' • الإجمالي: ' + this.money(sale.total_amount) + ' • المتبقي: ' + this.money(sale.remaining_debt) + '</div>';
            html += '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>المنتج</th><th>الكمية</th><th>مرتجع سابقًا</th><th>سعر الاسترداد/وحدة</th><th>كمية الإرجاع</th><th>الحالة</th></tr></thead><tbody>';
            (sale.items || []).forEach(function (item) {
                const refundable = self.num(item.quantity) - self.num(item.returned_quantity);
                const unitRefund = self.num(item.quantity) > 0 ? self.num(item.net_total) / self.num(item.quantity) : 0;
                html += '<tr><td>' + self.escapeHtml(item.product_name_snapshot) + (item.variant_snapshot ? ' - ' + self.escapeHtml(item.variant_snapshot) : '') + '</td>'
                    + '<td>' + self.num(item.quantity) + '</td><td>' + self.num(item.returned_quantity) + '</td>'
                    + '<td>' + self.money(unitRefund) + '</td>'
                    + '<td><input type="number" min="0" max="' + refundable + '" value="0" class="form-control ret-qty" data-sale-item-id="' + item.id + '" data-unit="' + unitRefund + '" style="width:90px;" ' + (refundable <= 0 ? 'disabled' : '') + '></td>'
                    + '<td><select class="form-select ret-condition" style="width:130px;" ' + (refundable <= 0 ? 'disabled' : '') + '><option value="resellable">قابل لإعادة البيع</option><option value="damaged">تالف</option><option value="needs_inspection">يحتاج فحصًا</option><option value="non_resellable">غير قابل لإعادة البيع</option></select></td></tr>';
            });
            html += '</tbody></table></div>';
            html += '<div class="form-grid mt-3">'
                + '<div><label>سبب الإرجاع (اختياري)</label><input id="ret-reason" class="form-control" placeholder="سبب الإرجاع (اختياري)"></div>'
                + '<div><label>طريقة الاسترداد</label><select id="ret-method" class="form-select"><option value="cash">نقدي</option><option value="card">بطاقة</option><option value="debt_deduction">خصم من الدين</option></select></div>'
                + '<div><label>نوع العملية</label><select id="ret-type" class="form-select"><option value="refund">استرداد</option><option value="exchange">استبدال</option></select></div>'
                + '<div><label>ملاحظات</label><input id="ret-notes" class="form-control"></div>'
                + '</div>';
            html += '<div class="mt-2" style="padding:10px;background:var(--levi-primary-soft);border-radius:8px;">إجمالي الاسترداد التقديري: <strong id="ret-total" style="font-size:16px;color:var(--levi-primary);">0.00</strong></div>';
            const footer = '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إلغاء</button>'
                + '<button type="button" class="btn btn-primary" onclick="LeviPOS.submitReturn(' + sale.id + ')">حفظ المرتجع</button>';
            this.openModal('إنشاء مرتجع للفاتورة ' + (sale.invoice_number || ''), html, footer, 'wide');
            document.querySelectorAll('.ret-qty').forEach(function (input) {
                input.addEventListener('input', function () { self.updateReturnTotals(); });
            });
            this.updateReturnTotals();
        },

        updateReturnTotals() {
            let total = 0;
            document.querySelectorAll('.ret-qty').forEach(function (input) {
                const qty = this.num(input.value);
                const unit = this.num(input.getAttribute('data-unit'));
                total += qty * unit;
            }, this);
            const el = document.getElementById('ret-total');
            if (el) el.textContent = this.money(total);
        },

        async submitReturn(saleId) {
            const reason = document.getElementById('ret-reason').value.trim();
            const items = [];
            document.querySelectorAll('.ret-qty').forEach(function (input) {
                const qty = this.num(input.value);
                if (qty > 0) {
                    items.push({
                        sale_item_id: Number(input.getAttribute('data-sale-item-id')),
                        quantity: qty,
                        item_condition: input.closest('tr').querySelector('.ret-condition').value
                    });
                }
            }, this);
            if (!items.length) { this.toast('أدخل كمية إرجاع لصنف واحد على الأقل.', 'warning'); return; }
            const result = await this.apiPost('returns.create', {
                sale_id: saleId,
                reason: reason,
                return_type: document.getElementById('ret-type').value,
                refund_method: document.getElementById('ret-method').value,
                notes: document.getElementById('ret-notes').value.trim(),
                items: items
            });
            if (!result) return;
            if (result.open_cash_drawer) this.openCashDrawer();
            this.toast('تم تسجيل المرتجع وتحديث المخزون والحسابات.', 'success');
            this.closeModal();
            this.state.allProducts = [];
            this.loadReturns();
        },

        /* =================================================================
         * Held invoices
         * ================================================================= */
        async loadHeldInvoices() {
            const data = await this.apiGet('pos.held_invoices');
            if (!data) return;
            this.setSidebarBadge('sidebar-badge-held', (data.held_invoices || []).length, 'amber');
            const self = this;
            this.renderTable('held-table', [
                { label: 'الفاتورة', render: function (r) { return '<strong>' + self.escapeHtml(r.invoice_number) + '</strong>'; } },
                { label: 'التاريخ', render: function (r) { return self.dateTime(r.sale_date); } },
                { label: 'العميل', render: function (r) { return self.escapeHtml(r.customer_name || 'عميل نقدي'); } },
                { label: 'الأصناف', render: function (r) { return self.num(r.items_count); } },
                { label: 'الإجمالي', render: function (r) { return '<span class="money">' + self.money(r.total_amount) + '</span>'; } },
                { label: 'الكاشير', render: function (r) { return self.escapeHtml(r.cashier_name || ''); } },
                { label: 'إجراءات', render: function (r) {
                    return '<div style="display:flex;gap:4px;">'
                        + (self.has('pos.resume_held') ? '<button class="btn btn-sm btn-primary" onclick="LeviPOS.resumeHeldInvoice(' + r.id + ')">استئناف</button>' : '')
                        + '<button class="btn btn-sm btn-danger" onclick="LeviPOS.deleteHeldInvoice(' + r.id + ')">حذف</button>'
                        + '</div>';
                } }
            ], data.held_invoices || [], 'لا توجد فواتير معلقة.');
        },

        async resumeHeldInvoice(id) {
            const sale = await this.apiGet('pos.held_details', { sale_id: id });
            if (!sale) return;
            this.state.cart = (sale.items || []).map(function (item) {
                return {
                    key: String(item.product_id) + '|' + String(item.variant_id || ''),
                    product_id: item.product_id,
                    variant_id: item.variant_id || null,
                    name: (item.product_name_snapshot || '') + (item.variant_snapshot ? ' - ' + item.variant_snapshot : ''),
                    sku: item.sku_snapshot || '',
                    unit_price: this.num(item.unit_price),
                    quantity: this.num(item.quantity),
                    discount_amount: this.num(item.discount_amount),
                    max_stock: 999999,
                    batch_number: item.batch_number_snapshot || '',
                    expiry_date: item.expiry_date_snapshot || '',
                    requires_prescription: false,
                    purchase_cost: 0
                };
            }, this);
            this.state.heldSaleId = sale.id;
            this.state.customer = sale.customer_id ? { id: sale.customer_id, name: sale.customer_name || '' } : null;
            const hidden = document.getElementById('pos-customer-id');
            const input = document.getElementById('pos-customer-search');
            if (hidden) hidden.value = sale.customer_id || '';
            if (input) input.value = sale.customer_name || '';
            this.navigate('pos');
            setTimeout(function () { this.renderCart(); }.bind(this), 150);
        },

        async deleteHeldInvoice(id) {
            if (!window.confirm('هل تريد حذف هذه الفاتورة المعلقة؟')) return;
            const result = await this.apiPost('pos.delete_held', { sale_id: id });
            if (!result) return;
            this.toast('تم حذف الفاتورة المعلقة.', 'success');
            this.loadHeldInvoices();
        },

        /* =================================================================
         * Expenses
         * ================================================================= */
        async loadExpenses() {
            const start = document.getElementById('expenses-start');
            const end = document.getElementById('expenses-end');
            const results = await Promise.all([
                this.apiGet('expenses.list', {
                    start_date: start ? start.value : '',
                    end_date: end ? end.value : '',
                    limit: 400
                }),
                this.apiGet('expenses.categories')
            ]);
            if (results[0]) {
                const self = this;
                const expenses = results[0].expenses || [];
                this.renderTable('expenses-table', [
                    { label: 'رقم المصروف', render: function (r) { return '<strong>' + self.escapeHtml(r.expense_number) + '</strong>'; } },
                    { label: 'التصنيف', render: function (r) { return self.escapeHtml(r.category_name || ''); } },
                    { label: 'الوصف', render: function (r) { return self.escapeHtml(r.description || ''); } },
                    { label: 'التاريخ', render: function (r) { return self.dateOnly(r.expense_date); } },
                    { label: 'المبلغ', render: function (r) { return '<span class="money">' + self.money(r.amount_in_base) + '</span>'; } },
                    { label: 'طريقة الدفع', render: function (r) { return self.statusLabel(r.payment_method); } },
                    { label: 'المستخدم', render: function (r) { return self.escapeHtml(r.created_by_name || ''); } },
                    { label: 'إجراءات', render: function (r) {
                        return '<div style="display:flex;gap:4px;">'
                            + '<button class="btn btn-sm btn-outline" onclick="LeviPOS.openExpenseModal(' + r.id + ')">تعديل</button>'
                            + '<button class="btn btn-sm btn-danger" onclick="LeviPOS.archiveExpense(' + r.id + ')">أرشفة</button>'
                            + '</div>';
                    } }
                ], expenses, 'لا توجد مصروفات في الفترة المحددة.');
            }
            if (results[1]) {
                this.state.expenseCategories = results[1].categories || [];
            }
        },

        async openExpenseModal(id) {
            if (!this.state.expenseCategories.length) {
                const data = await this.apiGet('expenses.categories');
                if (data) this.state.expenseCategories = data.categories || [];
            }
            let expense = null;
            if (id) {
                const today = new Date().toISOString().substring(0, 10);
                const list = await this.apiGet('expenses.list', { start_date: '2000-01-01', end_date: today, limit: 500 });
                if (list) expense = (list.expenses || []).find(function (e) { return Number(e.id) === Number(id); });
            }
            if (id && !expense) { this.toast('لم يتم العثور على المصروف.', 'warning'); return; }
            const val = function (key, fallback) {
                return this.escapeHtml(expense && expense[key] != null ? expense[key] : (fallback == null ? '' : fallback));
            }.bind(this);
            const categoryOptions = this.options(this.state.expenseCategories, 'id', 'name', expense ? expense.category_id : '', 'اختر التصنيف...');
            const body = '<div class="form-grid">'
                + '<div><label>التصنيف *</label><select id="ef-category" class="form-select">' + categoryOptions + '</select></div>'
                + '<div><label>المبلغ *</label><input type="number" step="0.01" min="0" id="ef-amount" class="form-control" value="' + val('amount', '0') + '" ' + (id ? 'disabled' : '') + '></div>'
                + '<div><label>العملة</label><input id="ef-currency" class="form-control" value="' + val('currency_code', this.boot().baseCurrency || 'LYD') + '" ' + (id ? 'disabled' : '') + '></div>'
                + '<div><label>طريقة الدفع</label><select id="ef-method" class="form-select" ' + (id ? 'disabled' : '') + '><option value="cash">نقدي</option><option value="card">بطاقة</option><option value="transfer">تحويل</option><option value="cheque">شيك</option></select></div>'
                + '<div><label>تاريخ المصروف</label><input type="date" id="ef-date" class="form-control" value="' + val('expense_date', new Date().toISOString().substring(0, 10)) + '"></div>'
                + '<div><label>الوصف *</label><input id="ef-description" class="form-control" value="' + val('description') + '"></div>'
                + '<div><label>ملاحظات</label><input id="ef-notes" class="form-control" value="' + val('notes') + '"></div>'
                + '</div>'
                + (id ? '<div class="muted mt-2">لا يمكن تعديل المبلغ أو طريقة الدفع بعد الإنشاء. للتصحيح: أرشف المصروف وأنشئ بديلًا.</div>' : '');
            if (id && expense) {
                // set method after modal opens
                this.openModal('تعديل المصروف', body, '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إلغاء</button>'
                    + '<button type="button" class="btn btn-primary" onclick="LeviPOS.submitExpense(' + id + ')">حفظ</button>');
                setTimeout(function () {
                    const el = document.getElementById('ef-method');
                    if (el) el.value = expense.payment_method || 'cash';
                }, 30);
                return;
            }
            this.openModal('تسجيل مصروف', body, '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إلغاء</button>'
                + '<button type="button" class="btn btn-primary" onclick="LeviPOS.submitExpense(null)">حفظ</button>');
        },

        async submitExpense(id) {
            const value = function (fieldId) { const el = document.getElementById(fieldId); return el ? el.value.trim() : ''; };
            const category = value('ef-category');
            const description = value('ef-description');
            if (!category || !description) { this.toast('التصنيف والوصف مطلوبان.', 'warning'); return; }
            const payload = {
                category_id: category,
                description: description,
                expense_date: value('ef-date'),
                notes: value('ef-notes')
            };
            if (id) {
                payload.expense_id = id;
            } else {
                payload.amount = value('ef-amount') || 0;
                payload.currency_code = value('ef-currency') || (this.boot().baseCurrency || 'LYD');
                payload.payment_method = value('ef-method');
            }
            const result = await this.apiPost(id ? 'expenses.update' : 'expenses.create', payload);
            if (!result) return;
            this.toast(id ? 'تم تحديث المصروف.' : 'تم تسجيل المصروف.', 'success');
            this.closeModal();
            this.loadExpenses();
        },

        async archiveExpense(id) {
            const reason = window.prompt('سبب أرشفة/إلغاء المصروف (إجباري):');
            if (!reason || !reason.trim()) return;
            const result = await this.apiPost('expenses.archive', { expense_id: id, reason: reason.trim() });
            if (!result) return;
            this.toast('تم أرشفة المصروف وإرجاع مبلغه للصندوق إن كان نقديًا.', 'success');
            this.loadExpenses();
        },

        async openExpenseCategoryModal() {
            // Management view must include inactive categories so they can be
            // reactivated; the expense form keeps using the active-only list.
            const data = await this.apiGet('expenses.categories', { include_inactive: 1 });
            if (data) this.state.expenseCategories = data.categories || [];
            const self = this;
            let html = '<div class="table-responsive" style="max-height:260px;overflow:auto;"><table class="table table-sm"><thead><tr><th>التصنيف</th><th>الوصف</th><th>الحالة</th><th></th></tr></thead><tbody>';
            (this.state.expenseCategories || []).forEach(function (cat) {
                html += '<tr><td>' + self.escapeHtml(cat.name) + '</td><td>' + self.escapeHtml(cat.description || '') + '</td><td>' + self.badge(Number(cat.is_active) === 1 ? 'نشط' : 'معطل', Number(cat.is_active) === 1 ? 'green' : 'red') + '</td>'
                    + '<td><button class="btn btn-sm btn-outline" onclick="LeviPOS.openExpenseCategoryForm(' + cat.id + ')">تعديل</button></td></tr>';
            });
            html += '</tbody></table></div>';
            html += '<div class="mt-3" style="border-top:1px solid var(--levi-border);padding-top:10px;"><strong style="font-size:13px;">إضافة تصنيف جديد</strong>'
                + '<div class="form-grid mt-2"><div><label>اسم التصنيف *</label><input id="ec-name" class="form-control"></div>'
                + '<div><label>الوصف</label><input id="ec-description" class="form-control"></div>'
                + '<div><label><input type="checkbox" id="ec-active" checked> نشط</label></div></div></div>';
            this.openModal('إدارة تصنيفات المصروفات', html, '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إغلاق</button>'
                + '<button type="button" class="btn btn-primary" onclick="LeviPOS.submitExpenseCategory(null)">إضافة التصنيف</button>', 'wide');
        },

        openExpenseCategoryForm(id) {
            const cat = (this.state.expenseCategories || []).find(function (c) { return Number(c.id) === Number(id); });
            if (!cat) return;
            const body = '<div class="form-grid">'
                + '<div><label>اسم التصنيف *</label><input id="ecf-name" class="form-control" value="' + this.escapeHtml(cat.name) + '"></div>'
                + '<div><label>الوصف</label><input id="ecf-description" class="form-control" value="' + this.escapeHtml(cat.description || '') + '"></div>'
                + '<div><label><input type="checkbox" id="ecf-active" ' + (Number(cat.is_active) === 1 ? 'checked' : '') + '> نشط</label></div>'
                + '</div>';
            this.openModal('تعديل تصنيف المصروف', body, '<button type="button" class="btn btn-outline" onclick="LeviPOS.openExpenseCategoryModal()">رجوع</button>'
                + '<button type="button" class="btn btn-primary" onclick="LeviPOS.submitExpenseCategory(' + id + ')">حفظ</button>');
        },

        async submitExpenseCategory(id) {
            const prefix = id ? 'ecf-' : 'ec-';
            const value = function (suffix) { const el = document.getElementById(prefix + suffix); return el ? el.value.trim() : ''; };
            const name = value('name');
            if (!name) { this.toast('اسم التصنيف مطلوب.', 'warning'); return; }
            const payload = {
                name: name,
                description: value('description'),
                is_active: document.getElementById(prefix + 'active') && document.getElementById(prefix + 'active').checked ? 1 : 0
            };
            if (id) payload.id = id;
            const result = await this.apiPost('expenses.category_save', payload);
            if (!result) return;
            this.toast('تم حفظ التصنيف.', 'success');
            const data = await this.apiGet('expenses.categories', { include_inactive: 1 });
            if (data) this.state.expenseCategories = data.categories || [];
            this.renderSettingsExpenseCategories();
            this.openExpenseCategoryModal();
        },

        /* =================================================================
         * Cash register / shifts
         * ================================================================= */
        async loadCashRegister() {
            const status = await this.apiGet('cash_register.status');
            if (status) {
                this.state.session = status.my_session || status.session || null;
                this.state.registers = status.registers || [];
            }
            const statusEl = document.getElementById('cash-register-status');
            if (statusEl) {
                const session = this.state.session;
                statusEl.innerHTML = [
                    this.statCard('حالة الوردية', session ? 'مفتوحة' : 'مغلقة', session ? 'green' : 'red'),
                    this.statCard('رقم الوردية', session ? this.escapeHtml(session.session_number || '-') : '-', 'blue'),
                    this.statCard('الصندوق', session ? this.escapeHtml(session.register_name || '-') : '-', 'gray'),
                    this.statCard('رصيد الافتتاح', session ? this.money(session.opening_cash) : this.money(0), 'amber')
                ].join('');
            }
            const summaryEl = document.getElementById('cash-register-summary');
            const movementsEl = document.getElementById('cash-register-movements');
            if (this.state.session) {
                const sessionId = this.state.session.id;
                const results = await Promise.all([
                    this.apiGet('cash_register.summary', { session_id: sessionId }),
                    this.apiGet('cash_register.movements', { session_id: sessionId })
                ]);
                if (results[0] && summaryEl) {
                    const sum = results[0];
                    summaryEl.innerHTML = '<div class="stat-grid">'
                        + this.statCard('إجمالي المبيعات', this.money(sum.total_sales), 'blue')
                        + this.statCard('عدد الفواتير', this.num(sum.invoice_count), 'gray')
                        + this.statCard('مبيعات نقدية', this.money(sum.cash_sales), 'green')
                        + this.statCard('تحصيل ديون', this.money(sum.debt_collection), 'green')
                        + this.statCard('إيداعات يدوية', this.money(sum.manual_in), 'green')
                        + this.statCard('مرتجعات نقدية', this.money(sum.refund_out), 'red')
                        + this.statCard('إلغاءات نقدية', this.money(sum.cancel_out), 'red')
                        + this.statCard('مصروفات نقدية', this.money(sum.expense_out), 'red')
                        + this.statCard('مشتريات نقدية', this.money(sum.purchase_out), 'red')
                        + this.statCard('دفعات موردين', this.money(sum.supplier_payment_out), 'red')
                        + this.statCard('سحوبات يدوية', this.money(sum.manual_out), 'red')
                        + this.statCard('النقد المتوقع في الدرج', this.money(sum.expected_cash), 'amber')
                        + this.statCard('مبيعات بطاقة/تحويل', this.money(sum.card_sales), 'gray')
                        + '</div>';
                }
                if (results[1] && movementsEl) {
                    const self = this;
                    this.renderTable(movementsEl, [
                        { label: 'التاريخ', render: function (r) { return self.dateTime(r.created_at); } },
                        { label: 'النوع', render: function (r) { return self.statusLabel(r.movement_type); } },
                        { label: 'المبلغ', render: function (r) { return '<span class="money">' + self.money(r.amount) + '</span>'; } },
                        { label: 'الوصف', render: function (r) { return self.escapeHtml(r.description || ''); } },
                        { label: 'المستخدم', render: function (r) { return self.escapeHtml(r.user_name || ''); } }
                    ], results[1].movements || [], 'لا توجد حركات نقدية في هذه الوردية.');
                }
            } else {
                if (summaryEl) {
                    summaryEl.innerHTML = '<div class="empty-state">لا توجد وردية مفتوحة حاليًا. افتح وردية لتسجيل الحركات النقدية وربط المبيعات بالصندوق.</div>';
                }
                if (movementsEl) movementsEl.innerHTML = '';
            }
            const sessions = this.has('cash_register.manage')
                ? await this.apiGet('cash_register.sessions', { limit: 60 })
                : null;
            if (sessions) {
                const self = this;
                this.renderTable('cash-register-sessions', [
                    { label: 'الوردية', render: function (r) { return '<strong>' + self.escapeHtml(r.session_number) + '</strong>'; } },
                    { label: 'الصندوق', render: function (r) { return self.escapeHtml(r.register_name || ''); } },
                    { label: 'المستخدم', render: function (r) { return self.escapeHtml(r.user_name || ''); } },
                    { label: 'الفتح', render: function (r) { return self.dateTime(r.opened_at); } },
                    { label: 'الإغلاق', render: function (r) { return r.closed_at ? self.dateTime(r.closed_at) : '-'; } },
                    { label: 'الافتتاحي', render: function (r) { return self.money(r.opening_cash); } },
                    { label: 'الفعلي', render: function (r) { return r.actual_cash != null ? self.money(r.actual_cash) : '-'; } },
                    { label: 'الفرق', render: function (r) { return r.discrepancy != null ? self.money(r.discrepancy) : '-'; } },
                    { label: 'الحالة', render: function (r) { return self.statusBadge(r.status); } }
                ], sessions.sessions || [], 'لا توجد ورديات سابقة.');
            }
            this.refreshSessionBadge();
        },

        async openShiftModal() {
            const self = this;
            const status = await this.apiGet('cash_register.status');
            if (status) {
                this.state.registers = status.registers || [];
                this.state.session = status.my_session || status.session || null;
            }
            if (this.state.session) {
                this.toast('توجد وردية مفتوحة بالفعل. أغلقها أولًا لفتح وردية جديدة.', 'warning');
                return;
            }
            if (!this.state.registers.length) {
                this.toast('لا يوجد صندوق معرف. أضف صندوقًا من شاشة الإعدادات أولًا.', 'warning');
                return;
            }
            const free = this.state.registers.find(function (r) { return !r.in_use; });
            const body = '<div class="form-grid">'
                + '<div><label>صندوقك الخاص *</label><select id="shift-register" class="form-select">'
                + this.options(this.state.registers, 'id', function (r) {
                    return (r.name || '') + ' (' + (r.code || '') + ')' + (r.in_use ? ' — مستخدم حاليًا' : '');
                }, free ? free.id : '', 'اختر الصندوق...')
                + '</select></div>'
                + '<div><label>رصيد الافتتاح النقدي *</label><input type="number" step="0.01" min="0" id="shift-opening" class="form-control" value="0"></div>'
                + '</div>'
                + '<div class="muted mt-2" style="font-size:12px;">' + (this.boot().requireOpenRegister
                    ? 'إلزام فتح وردية مفعّل: لا يمكن تسجيل مبيعات نقدية أو مرتجعات أو مصروفات نقدية بدون وردية مفتوحة.'
                    : 'يُنصح بفتح وردية قبل تسجيل المدفوعات النقدية لضبط حركة الصندوق والدرج.')
                + '<br>لا يمكن فتح وردية على صندوق مستخدم حاليًا من قِبل مستخدم آخر.</div>';
            const footer = '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إلغاء</button>'
                + '<button type="button" class="btn btn-success" onclick="LeviPOS.submitOpenShift()">فتح الوردية</button>';
            this.openModal('فتح وردية جديدة', body, footer);
            const select = document.getElementById('shift-register');
            if (select) {
                select.addEventListener('change', function () {
                    const chosen = self.state.registers.find(function (r) { return String(r.id) === String(select.value); });
                    if (chosen && chosen.in_use) {
                        self.toast('الصندوق يتم استخدامه حاليا. اختر صندوقًا آخر.', 'warning');
                        select.value = free ? String(free.id) : '';
                    }
                });
            }
        },

        async submitOpenShift() {
            const register = document.getElementById('shift-register');
            if (!register || !register.value) { this.toast('اختر الصندوق.', 'warning'); return; }
            const chosen = this.state.registers.find(function (r) { return String(r.id) === String(register.value); });
            if (chosen && chosen.in_use) {
                this.toast('الصندوق يتم استخدامه حاليا. اختر صندوقًا آخر.', 'warning');
                return;
            }
            const opening = this.num(document.getElementById('shift-opening').value);
            const result = await this.apiPost('cash_register.open', {
                register_id: register.value,
                opening_cash: opening
            });
            if (!result) return;
            this.toast('تم فتح الوردية بنجاح.', 'success');
            this.closeModal();
            this.state.session = result;
            this.loadCashRegister();
        },

        async closeShiftModal() {
            if (!this.state.session) {
                this.toast('لا توجد وردية مفتوحة لإغلاقها.', 'warning');
                return;
            }
            const sum = await this.apiGet('cash_register.summary', { session_id: this.state.session.id });
            if (!sum) return;
            const body = '<div class="stat-grid" style="margin-bottom:12px;">'
                + this.statCard('رصيد الافتتاح', this.money(sum.opening_cash), 'blue')
                + this.statCard('المبيعات النقدية', this.money(sum.cash_sales), 'green')
                + this.statCard('التحصيلات', this.money(sum.debt_collection), 'green')
                + this.statCard('المصروفات النقدية', this.money(sum.expense_out), 'red')
                + this.statCard('المرتجعات النقدية', this.money(sum.refund_out), 'red')
                + this.statCard('النقد المتوقع', this.money(sum.expected_cash), 'amber')
                + '</div>'
                + '<div class="form-grid">'
                + '<div><label>النقد الفعلي في الدرج *</label><input type="number" step="0.01" min="0" id="shift-actual" class="form-control" value="' + this.num(sum.expected_cash).toFixed(2) + '"></div>'
                + '<div><label>سبب الفرق (إن وجد)</label><input id="shift-reason" class="form-control" placeholder="مطلوب عند وجود فرق"></div>'
                + '</div>';
            const footer = '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إلغاء</button>'
                + '<button type="button" class="btn btn-warning" onclick="LeviPOS.submitCloseShift(' + sum.session_id + ')">إغلاق الوردية</button>';
            this.openModal('إغلاق الوردية', body, footer);
            this.state.closeShiftExpected = this.num(sum.expected_cash);
        },

        async submitCloseShift(sessionId) {
            const actual = this.num(document.getElementById('shift-actual').value);
            const reason = document.getElementById('shift-reason').value.trim();
            const expected = this.num(this.state.closeShiftExpected);
            const diff = actual - expected;
            if (Math.abs(diff) > 0.009 && !reason) {
                this.toast('يوجد فرق في النقد (' + diff.toFixed(2) + '). يرجى إدخال سبب الفرق.', 'warning');
                return;
            }
            if (!window.confirm('هل تريد إغلاق الوردية نهائيًا؟')) return;
            const result = await this.apiPost('cash_register.close', {
                session_id: sessionId,
                actual_cash: actual,
                discrepancy_reason: reason
            });
            if (!result) return;
            this.toast('تم إغلاق الوردية.', 'success');
            this.closeModal();
            this.state.session = null;
            this.loadCashRegister();
        },

        manualCashMovementModal() {
            if (!this.state.session) {
                this.toast('لا توجد وردية مفتوحة. افتح وردية أولًا.', 'warning');
                return;
            }
            const body = '<div class="form-grid">'
                + '<div><label>نوع الحركة</label><select id="cm-type" class="form-select"><option value="manual_in">إيداع نقدي يدوي</option><option value="manual_out">سحب نقدي يدوي</option></select></div>'
                + '<div><label>المبلغ *</label><input type="number" step="0.01" min="0" id="cm-amount" class="form-control" value="0"></div>'
                + '<div><label>الوصف *</label><input id="cm-description" class="form-control" placeholder="مثال: إيداع رأس مال إضافي"></div>'
                + '</div>';
            const footer = '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إلغاء</button>'
                + '<button type="button" class="btn btn-primary" onclick="LeviPOS.submitManualCashMovement()">تسجيل الحركة</button>';
            this.openModal('حركة نقدية يدوية', body, footer);
        },

        async submitManualCashMovement() {
            const amount = this.num(document.getElementById('cm-amount').value);
            const description = document.getElementById('cm-description').value.trim();
            if (amount <= 0) { this.toast('أدخل مبلغًا صحيحًا.', 'warning'); return; }
            if (!description) { this.toast('وصف الحركة مطلوب.', 'warning'); return; }
            const result = await this.apiPost('cash_register.manual_movement', {
                session_id: this.state.session.id,
                movement_type: document.getElementById('cm-type').value,
                amount: amount,
                description: description
            });
            if (!result) return;
            this.toast('تم تسجيل الحركة النقدية.', 'success');
            this.closeModal();
            this.loadCashRegister();
        },

        /* =================================================================
         * Pharmacy: batches & expiry
         * ================================================================= */
        expiryTone(row) {
            const days = this.num(row.days_left);
            if (days < 0) return 'red';
            if (days <= this.num(this.boot().expiryAlertDays || 30)) return 'amber';
            return 'green';
        },

        batchStatus(row) {
            if (this.num(row.quantity) <= 0) return this.badge('نفدت', 'gray');
            const status = String(row.expiry_status || 'valid');
            if (status === 'expired') return this.badge('منتهية', 'red');
            if (status === 'near_expiry') return this.badge('قريبة الانتهاء', 'amber');
            return this.badge('سارية', 'green');
        },

        batchEmoji(batch) {
            const text = String(((batch && batch.active_ingredient) || '') + ' ' + ((batch && batch.dosage_form) || '') + ' ' + ((batch && batch.product_name) || ''));
            if (/شراب|سائل|قطرات|syrup|drop/i.test(text)) return '🍼';
            if (/مضاد|أموكس|بنسلين|تتراسكلين|ماكروليد|antibiotic/i.test(text)) return '🧪';
            if (/فيتام|كوليكال|مكمل|vitamin/i.test(text)) return '🌿';
            return '💊';
        },

        async loadBatches() {
            const search = document.getElementById('batches-search');
            const status = document.getElementById('batches-status');
            const data = await this.apiGet('batches.list', {
                q: search ? search.value.trim() : '',
                status: status ? status.value : '',
                limit: 300
            });
            if (!data) return;
            this.state.batches = data.batches || [];
            const self = this;
            this.renderTable('batches-table', [
                { label: 'الدواء', render: function (r) {
                    return '<span class="wcx-row-em" aria-hidden="true">' + self.batchEmoji(r) + '</span>'
                        + '<strong>' + self.escapeHtml(r.product_name || '') + '</strong>'
                        + '<br><span class="muted">' + self.escapeHtml(r.product_sku || '') + '</span>';
                } },
                { label: 'المادة الفعالة', render: function (r) { return self.escapeHtml(r.active_ingredient || '-'); } },
                { label: 'رقم الدفعة', render: function (r) { return '<strong>' + self.escapeHtml(r.batch_number || '') + '</strong>'; } },
                { label: 'التصنيع', render: function (r) { return self.dateOnly(r.manufacturing_date) || '-'; } },
                { label: 'الانتهاء', render: function (r) { return self.dateOnly(r.expiry_date); } },
                { label: 'المتبقي', render: function (r) { return self.badge(String(self.num(r.quantity)), self.num(r.quantity) <= 0 ? 'gray' : 'green'); } },
                { label: 'الحالة', render: function (r) { return self.batchStatus(r); } },
                { label: 'إجراءات', render: function (r) {
                    return '<div class="table-actions">'
                        + '<button class="btn btn-sm btn-outline" onclick="LeviPOS.openBatchModal(' + r.id + ')">تعديل</button>'
                        + '<button class="btn btn-sm btn-danger" onclick="LeviPOS.deleteBatch(' + r.id + ')">حذف</button>'
                        + '</div>';
                } }
            ], this.state.batches, 'لا توجد دفعات مطابقة.');
        },

        async openBatchModal(id) {
            await this.ensureReferenceData();
            await this.ensureProductCatalog();
            const batch = id ? (this.state.batches || []).find(function (b) { return Number(b.id) === Number(id); }) : null;
            if (id && !batch) { this.toast('لم يتم العثور على الدفعة.', 'warning'); return; }
            const val = function (key, fallback) {
                return this.escapeHtml(batch && batch[key] != null ? batch[key] : (fallback == null ? '' : fallback));
            }.bind(this);
            const today = new Date().toISOString().substring(0, 10);
            const selectedProduct = batch ? this.productKey({
                product_id: batch.product_id,
                product_name: batch.product_name,
                sku: batch.product_sku,
                variant_id: null
            }) : '';
            const body = '<div class="form-grid">'
                + '<div><label>الدواء *</label><select id="btf-product" class="form-select">' + this.productOptionHtml(this.state.allProducts, selectedProduct) + '</select></div>'
                + '<div><label>رقم الدفعة *</label><input id="btf-number" class="form-control" maxlength="120" value="' + val('batch_number') + '"></div>'
                + '<div><label>تاريخ التصنيع</label><input type="date" id="btf-manufacturing" class="form-control" value="' + val('manufacturing_date') + '"></div>'
                + '<div><label>تاريخ الانتهاء *</label><input type="date" id="btf-expiry" class="form-control" value="' + val('expiry_date', today) + '"></div>'
                + '<div><label>الكمية *</label><input type="number" min="0" id="btf-quantity" class="form-control" value="' + val('quantity', '0') + '"></div>'
                + '<div><label>تكلفة الشراء</label><input type="number" step="0.01" min="0" id="btf-cost" class="form-control" value="' + val('purchase_cost', '0') + '"></div>'
                + '</div>'
                + '<div class="muted mt-2" style="font-size:11.5px;">سيتم اعتماد الدفعات الأقرب للانتهاء أولاً عند البيع (FEFO).</div>';
            const footer = '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إلغاء</button>'
                + '<button type="button" class="btn btn-primary" onclick="LeviPOS.submitBatch(' + (id || 'null') + ')">حفظ الدفعة</button>';
            this.openModal(id ? 'تعديل الدفعة' : 'إضافة دفعة دواء', body, footer);
        },

        async submitBatch(id) {
            const productKey = document.getElementById('btf-product') ? document.getElementById('btf-product').value : '';
            const product = this.findProductByKey(productKey);
            if (!product) { this.toast('يرجى اختيار الدواء.', 'warning'); return; }
            const value = function (fieldId) { const el = document.getElementById(fieldId); return el ? el.value.trim() : ''; };
            const batchNumber = value('btf-number');
            const expiryDate = value('btf-expiry');
            if (!batchNumber || !expiryDate) { this.toast('رقم الدفعة وتاريخ الانتهاء مطلوبان.', 'warning'); return; }
            const payload = {
                product_id: Number(product.id),
                batch_number: batchNumber,
                manufacturing_date: value('btf-manufacturing') || null,
                expiry_date: expiryDate,
                quantity: value('btf-quantity') || 0,
                purchase_cost: value('btf-cost') || 0
            };
            if (id) payload.id = id;
            const result = await this.apiPost('batches.save', payload);
            if (!result) return;
            this.toast('تم حفظ الدفعة.', 'success');
            this.closeModal();
            this.loadBatches();
        },

        async deleteBatch(id) {
            if (!window.confirm('هل تريد حذف هذه الدفعة؟')) return;
            const result = await this.apiPost('batches.delete', { batch_id: id });
            if (!result) return;
            this.toast('تم حذف الدفعة.', 'success');
            this.loadBatches();
        },

        async loadExpiry() {
            const data = await this.apiGet('expiry.alerts');
            if (!data) return;
            this.state.expiryAlerts = data.alerts || [];
            const counts = data.counts || {};
            const summary = document.getElementById('expiry-summary');
            if (summary) {
                summary.innerHTML = this.statCard('منتهية', this.num(counts.expired), 'red')
                    + this.statCard('قريبة الانتهاء', this.num(counts.near_expiry), 'amber')
                    + this.statCard('نافذة التنبيه', (data.alert_windows && data.alert_windows.length
                        ? data.alert_windows.map(function (w) { return w + ' يوم'; }).join(' / ')
                        : this.num(data.alert_days || 30) + ' يوم'), 'blue');
            }
            const self = this;
            this.renderTable('expiry-table', [
                { label: 'الدواء', render: function (r) { return '<strong>' + self.escapeHtml(r.product_name || '') + '</strong>'; } },
                { label: 'رقم الدفعة', render: function (r) { return self.escapeHtml(r.batch_number || ''); } },
                { label: 'الكمية', render: function (r) { return self.num(r.quantity); } },
                { label: 'الانتهاء', render: function (r) { return self.dateOnly(r.expiry_date); } },
                { label: 'المتبقي', render: function (r) { return self.num(r.days_left) + ' يوم'; } },
                { label: 'الحالة', render: function (r) { return self.batchStatus(r); } },
                { label: 'إجراءات', render: function (r) { return '<button class="btn btn-sm btn-outline" onclick="LeviPOS.openBatchModal(' + r.id + ')">ضبط الدفعة</button>'; } }
            ], this.state.expiryAlerts, 'لا توجد دفعات منتهية أو قريبة من الانتهاء.');
        },

        /* =================================================================
         * Pharmacy: doctors
         * ================================================================= */
        async loadDoctors() {
            const search = document.getElementById('doctors-search');
            const data = await this.apiGet('doctors.list', { q: search ? search.value.trim() : '' });
            if (!data) return;
            this.state.doctors = data.doctors || [];
            const self = this;
            this.renderTable('doctors-table', [
                { label: 'الطبيب', render: function (r) { return '<strong>' + self.escapeHtml(r.name || '') + '</strong>'; } },
                { label: 'التخصص', render: function (r) { return self.escapeHtml(r.specialty || '-'); } },
                { label: 'الهاتف', render: function (r) { return self.escapeHtml(r.phone || '-'); } },
                { label: 'العيادة', render: function (r) { return self.escapeHtml(r.clinic || '-'); } },
                { label: 'الحالة', render: function (r) { return self.badge(Number(r.is_active) === 1 ? 'نشط' : 'غير نشط', Number(r.is_active) === 1 ? 'green' : 'gray'); } },
                { label: 'إجراءات', render: function (r) {
                    return '<div class="table-actions">'
                        + '<button class="btn btn-sm btn-outline" onclick="LeviPOS.openDoctorModal(' + r.id + ')">تعديل</button>'
                        + '<button class="btn btn-sm btn-danger" onclick="LeviPOS.deleteDoctor(' + r.id + ')">حذف</button>'
                        + '</div>';
                } }
            ], this.state.doctors, 'لا يوجد أطباء مطابقون.');
        },

        openDoctorModal(id) {
            const doctor = id ? (this.state.doctors || []).find(function (d) { return Number(d.id) === Number(id); }) : null;
            if (id && !doctor) { this.toast('لم يتم العثور على الطبيب.', 'warning'); return; }
            const val = function (key) { return this.escapeHtml(doctor && doctor[key] != null ? doctor[key] : ''); }.bind(this);
            const body = '<div class="form-grid">'
                + '<div><label>اسم الطبيب *</label><input id="df-name" class="form-control" value="' + val('name') + '"></div>'
                + '<div><label>التخصص</label><input id="df-specialty" class="form-control" value="' + val('specialty') + '"></div>'
                + '<div><label>الهاتف</label><input id="df-phone" class="form-control" value="' + val('phone') + '"></div>'
                + '<div><label>العيادة</label><input id="df-clinic" class="form-control" value="' + val('clinic') + '"></div>'
                + '<div><label><input type="checkbox" id="df-active" ' + (!doctor || Number(doctor.is_active) === 1 ? 'checked' : '') + '> نشط</label></div>'
                + '</div>';
            const footer = '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إلغاء</button>'
                + '<button type="button" class="btn btn-primary" onclick="LeviPOS.submitDoctor(' + (id || 'null') + ')">حفظ الطبيب</button>';
            this.openModal(id ? 'تعديل بيانات الطبيب' : 'إضافة طبيب', body, footer);
        },

        async submitDoctor(id) {
            const value = function (fieldId) { const el = document.getElementById(fieldId); return el ? el.value.trim() : ''; };
            const name = value('df-name');
            if (!name) { this.toast('اسم الطبيب مطلوب.', 'warning'); return; }
            const payload = {
                name: name,
                specialty: value('df-specialty'),
                phone: value('df-phone'),
                clinic: value('df-clinic'),
                is_active: document.getElementById('df-active') && document.getElementById('df-active').checked ? 1 : 0
            };
            if (id) payload.id = id;
            const result = await this.apiPost('doctors.save', payload);
            if (!result) return;
            this.toast('تم حفظ بيانات الطبيب.', 'success');
            this.closeModal();
            this.loadDoctors();
        },

        async deleteDoctor(id) {
            if (!window.confirm('هل تريد حذف هذا الطبيب؟')) return;
            const result = await this.apiPost('doctors.delete', { doctor_id: id });
            if (!result) return;
            this.toast('تم حذف الطبيب.', 'success');
            this.loadDoctors();
        },

        /* =================================================================
         * Pharmacy: prescriptions
         * ================================================================= */
        prescriptionStatus(r) {
            if (r.status === 'dispensed') return this.badge('مصروفة', 'green');
            if (r.status === 'cancelled') return this.badge('ملغاة', 'red');
            return this.badge('صادرة', 'blue');
        },

        async loadPrescriptions() {
            const search = document.getElementById('prescriptions-search');
            const status = document.getElementById('prescriptions-status');
            const data = await this.apiGet('prescriptions.list', {
                q: search ? search.value.trim() : '',
                status: status ? status.value : '',
                limit: 300
            });
            if (!data) return;
            this.state.prescriptions = data.prescriptions || [];
            const self = this;
            this.renderTable('prescriptions-table', [
                { label: 'رقم الروشتة', render: function (r) { return '<strong>' + self.escapeHtml(r.prescription_number || '') + '</strong>'; } },
                { label: 'المريض', render: function (r) { return self.escapeHtml(r.patient_name || ''); } },
                { label: 'الطبيب', render: function (r) { return self.escapeHtml(r.doctor_name || '-'); } },
                { label: 'التاريخ', render: function (r) { return self.dateOnly(r.prescription_date); } },
                { label: 'الحالة', render: function (r) { return self.prescriptionStatus(r); } },
                { label: 'ملاحظات', render: function (r) { return self.escapeHtml(r.notes || '-'); } },
                { label: 'إجراءات', render: function (r) {
                    return '<div class="table-actions">'
                        + '<button class="btn btn-sm btn-outline" onclick="LeviPOS.openPrescriptionModal(' + r.id + ')">عرض / تعديل</button>'
                        + '<button class="btn btn-sm btn-danger" onclick="LeviPOS.deletePrescription(' + r.id + ')">حذف</button>'
                        + '</div>';
                } }
            ], this.state.prescriptions, 'لا توجد روشتات مطابقة.');
        },

        async openPrescriptionModal(id) {
            await this.ensureProductCatalog();
            const results = await Promise.all([
                this.apiGet('customers.list', { limit: 300 }),
                this.apiGet('doctors.list', { limit: 300 }),
                id ? this.apiGet('prescriptions.get', { prescription_id: id }) : Promise.resolve(null)
            ]);
            const customers = results[0] ? (results[0].customers || []) : [];
            const doctors = results[1] ? (results[1].doctors || []) : [];
            const rx = id ? results[2] : null;
            if (id && !rx) return;
            const val = function (key, fallback) {
                return this.escapeHtml(rx && rx[key] != null ? rx[key] : (fallback == null ? '' : fallback));
            }.bind(this);
            const patientOptions = this.options(customers, 'id', function (c) {
                return (c.name || '') + (c.phone ? ' — ' + c.phone : '');
            }.bind(this), rx ? rx.patient_id : (this.state.customer ? this.state.customer.id : ''), 'اختر المريض...');
            const doctorOptions = this.options(doctors, 'id', 'name', rx ? rx.doctor_id : '', 'بدون طبيب');
            const statusOptions = [
                { v: 'issued', l: 'صادرة' }, { v: 'dispensed', l: 'مصروفة' }, { v: 'cancelled', l: 'ملغاة' }
            ];
            const body = '<div class="form-grid">'
                + '<div><label>المريض *</label><select id="rxf-patient" class="form-select">' + patientOptions + '</select></div>'
                + '<div><label>الطبيب</label><select id="rxf-doctor" class="form-select">' + doctorOptions + '</select></div>'
                + '<div><label>تاريخ الروشتة</label><input type="date" id="rxf-date" class="form-control" value="' + val('prescription_date', new Date().toISOString().substring(0, 10)).substring(0, 10) + '"></div>'
                + '<div><label>الحالة</label><select id="rxf-status" class="form-select">' + this.options(statusOptions, 'v', 'l', rx ? rx.status : 'issued', '') + '</select></div>'
                + '</div>'
                + '<div class="mt-3"><label>ملاحظات</label><textarea id="rxf-notes" class="form-control" rows="2">' + val('notes') + '</textarea></div>'
                + '<div class="mt-3"><div class="d-flex justify-content-between align-items-center mb-2"><strong style="font-size:13px;">الأدوية المطلوبة</strong>'
                + '<button type="button" class="btn btn-sm btn-outline" onclick="LeviPOS.addPrescriptionItemRow()">+ دواء</button></div>'
                + '<div id="rxf-items"></div></div>';
            const footer = '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إلغاء</button>'
                + '<button type="button" class="btn btn-primary" onclick="LeviPOS.submitPrescription(' + (id || 'null') + ')">حفظ الروشتة</button>';
            this.openModal(id ? 'تعديل الروشتة' : 'إضافة روشتة', body, footer, 'wide');
            const items = rx && Array.isArray(rx.items) ? rx.items : [];
            if (items.length) {
                items.forEach(function (item) { this.addPrescriptionItemRow(item.product_id, item.quantity, item.dosage_instructions); }, this);
            } else {
                this.addPrescriptionItemRow();
            }
        },

        addPrescriptionItemRow(productId, quantity, instructions) {
            const wrap = document.getElementById('rxf-items');
            if (!wrap) return;
            const row = document.createElement('div');
            row.className = 'rx-item-row';
            row.style.cssText = 'display:grid;grid-template-columns:2fr .7fr 2fr auto;gap:6px;margin-bottom:6px;align-items:end;';
            row.innerHTML = '<div><label style="font-size:10.5px;">الدواء</label><select class="form-select rxi-product">' + this.productOptionHtml(this.state.allProducts, productId || '') + '</select></div>'
                + '<div><label style="font-size:10.5px;">الكمية</label><input type="number" min="1" class="form-control rxi-qty" value="' + this.escapeHtml(quantity == null ? '1' : quantity) + '"></div>'
                + '<div><label style="font-size:10.5px;">تعليمات الجرعة</label><input class="form-control rxi-instructions" value="' + this.escapeHtml(instructions || '') + '"></div>'
                + '<div><button type="button" class="btn btn-sm btn-danger" onclick="this.closest(\'.rx-item-row\').remove()">×</button></div>';
            wrap.appendChild(row);
        },

        async submitPrescription(id) {
            const value = function (fieldId) { const el = document.getElementById(fieldId); return el ? el.value.trim() : ''; };
            const patientId = value('rxf-patient');
            if (!patientId) { this.toast('يرجى اختيار المريض.', 'warning'); return; }
            const items = [];
            document.querySelectorAll('#rxf-items .rx-item-row').forEach(function (row) {
                const productKey = row.querySelector('.rxi-product').value;
                const product = this.findProductByKey(productKey);
                if (!product) return;
                items.push({
                    product_id: Number(product.id),
                    quantity: row.querySelector('.rxi-qty').value || 1,
                    dosage_instructions: row.querySelector('.rxi-instructions').value.trim()
                });
            }, this);
            if (!items.length) { this.toast('أضف دواءً واحدًا على الأقل.', 'warning'); return; }
            const payload = {
                patient_id: Number(patientId),
                doctor_id: value('rxf-doctor') || null,
                prescription_date: value('rxf-date') || new Date().toISOString().substring(0, 10),
                status: value('rxf-status') || 'issued',
                notes: value('rxf-notes'),
                items: items
            };
            if (id) payload.id = id;
            const result = await this.apiPost('prescriptions.save', payload);
            if (!result) return;
            this.toast('تم حفظ الروشتة.', 'success');
            this.closeModal();
            this.loadPrescriptions();
        },

        async deletePrescription(id) {
            if (!window.confirm('هل تريد حذف هذه الروشتة؟')) return;
            const result = await this.apiPost('prescriptions.delete', { prescription_id: id });
            if (!result) return;
            this.toast('تم حذف الروشتة.', 'success');
            this.loadPrescriptions();
        },

        /* =================================================================
         * Reports
         * ================================================================= */
        async loadReportTypes() {
            const select = document.getElementById('report-type');
            const start = document.getElementById('report-start');
            const end = document.getElementById('report-end');
            if (start && !start.value) {
                const now = new Date();
                start.value = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().substring(0, 10);
            }
            if (end && !end.value) end.value = new Date().toISOString().substring(0, 10);
            if (!this.state.reportTypes) {
                const data = await this.apiGet('reports.types');
                if (!data) return;
                this.state.reportTypes = data.types || {};
            }
            if (select) {
                const types = this.state.reportTypes || {};
                select.innerHTML = Object.keys(types).map(function (key) {
                    return '<option value="' + this.escapeHtml(key) + '">' + this.escapeHtml(types[key]) + '</option>';
                }, this).join('');
            }
            this.generateReport();
        },

        async generateReport() {
            const select = document.getElementById('report-type');
            const start = document.getElementById('report-start');
            const end = document.getElementById('report-end');
            const output = document.getElementById('report-output');
            if (!select || !select.value) return;
            if (output) output.innerHTML = '<div class="empty-state">جارٍ إنشاء التقرير...</div>';
            const data = await this.apiGet('reports.get', {
                report_type: select.value,
                start_date: start ? start.value : '',
                end_date: end ? end.value : ''
            });
            if (!data) {
                if (output) output.innerHTML = '<div class="empty-state">تعذر إنشاء التقرير.</div>';
                return;
            }
            this.renderReport(data);
        },

        printReport() {
            const select = document.getElementById('report-type');
            if (!select || !select.value) {
                this.toast('اختر نوع التقرير أولًا.', 'warning');
                return;
            }
            const start = document.getElementById('report-start');
            const end = document.getElementById('report-end');
            const params = new URLSearchParams({
                report_type: select.value,
                start_date: start ? start.value : '',
                end_date: end ? end.value : ''
            });
            window.open('print_report.php?' + params.toString(), '_blank');
        },

        renderReport(data) {
            const output = document.getElementById('report-output');
            if (!output) return;
            const self = this;
            const columns = data.columns || [];
            const rows = data.rows || [];
            const summary = data.summary || {};
            const period = data.period || {};
            let html = '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px;">'
                + '<h3 style="font-size:16px;margin:0;">' + this.escapeHtml(data.title || '') + '</h3>'
                + '<span class="muted" style="font-size:12px;">' + this.escapeHtml(period.start_date || '') + ' ← ' + this.escapeHtml(period.end_date || '') + ' • ' + this.escapeHtml(data.generated_at || '') + '</span>'
                + '</div>';
            const summaryKeys = Object.keys(summary || {});
            if (summaryKeys.length) {
                html += '<div class="stat-grid" style="margin-bottom:12px;">';
                summaryKeys.forEach(function (key) {
                    const value = summary[key];
                    html += self.statCard(self.reportFieldLabel(key, columns), typeof value === 'number' ? self.num(value).toFixed(2) : self.escapeHtml(value), 'blue');
                });
                html += '</div>';
            }
            if (!rows.length) {
                html += '<div class="empty-state">لا توجد بيانات في الفترة المحددة.</div>';
            } else {
                html += '<div class="table-responsive"><table class="table table-sm"><thead><tr>';
                columns.forEach(function (col) { html += '<th>' + self.escapeHtml(col.label) + '</th>'; });
                html += '</tr></thead><tbody>';
                rows.forEach(function (row) {
                    html += '<tr>';
                    columns.forEach(function (col) {
                        html += '<td>' + self.formatReportValue(row[col.key], col.type) + '</td>';
                    });
                    html += '</tr>';
                });
                html += '</tbody></table></div>';
            }
            html += '<div class="mt-2" style="display:flex;gap:8px;flex-wrap:wrap;">'
                + '<button type="button" class="btn btn-sm btn-primary" onclick="LeviPOS.printReport()">طباعة التقرير</button>';
            if (this.has('reports.export')) {
                html += '<button type="button" class="btn btn-sm btn-outline" onclick="LeviPOS.exportReportCsv()">تصدير CSV</button>';
            }
            html += '</div>';
            output.innerHTML = html;
            this.state.lastReport = data;
        },

        reportFieldLabel(key, columns) {
            const map = {
                report_date: 'التاريخ', total_sales: 'إجمالي المبيعات', gross_sales: 'إجمالي المبيعات',
                billed_sales: 'المبيعات المفوترة', net_sales: 'صافي المبيعات', discounts: 'الخصومات',
                tax_amount: 'الضريبة', returns: 'المرتجعات', sales_returns: 'المرتجعات',
                cash_amount: 'كاش', card_amount: 'بطاقة', transfer_amount: 'تحويل',
                gross_profit: 'الربح الإجمالي', net_profit: 'صافي الربح', expenses: 'المصروفات',
                operating_expenses: 'المصروفات التشغيلية', total_discounts: 'إجمالي الخصومات',
                total_cogs: 'إجمالي التكلفة', net_cogs: 'صافي التكلفة', gross_cogs: 'تكلفة المبيعات',
                returned_cogs: 'تكلفة المرتجعات', collected_amount: 'المبلغ المحصّل',
                credit_debt_amount: 'المبلغ الآجل', inventory_valuation: 'قيمة المخزون',
                invoice_count: 'عدد الفواتير', return_count: 'عدد المرتجعات', expense_count: 'عدد المصروفات',
                total_refunds: 'إجمالي المرتجعات', total_expenses: 'إجمالي المصروفات', net_qty: 'صافي الكمية',
                net_revenue: 'صافي الإيراد', qty_sold: 'الكمية المباعة', quantity_sold: 'الكمية المباعة',
                qty_returned: 'الكمية المرتجعة', total_value: 'القيمة الإجمالية', total_debt: 'إجمالي الديون',
                total_amount: 'الإجمالي', paid_amount: 'المدفوع', remaining_amount: 'المتبقي', debt: 'الدين المتبقي',
                total_refund_amount: 'إجمالي الاسترداد', cash_refund_amount: 'استرداد نقدي',
                card_refund_amount: 'استرداد بطاقة', debt_reduction_amount: 'خصم دين',
                quantity: 'الكمية', stock_value: 'قيمة المخزون', potential_revenue: 'الإيراد المحتمل',
                deficit: 'النقص', current_balance: 'الرصيد المستحق', current_debt: 'الدين الحالي',
                amount_in_base: 'المبلغ', opening_cash: 'الرصيد الافتتاحي', expected_cash: 'المتوقع',
                actual_cash: 'الفعلي', discrepancy: 'الفرق', item_discount: 'خصم الأصناف',
                invoice_discount: 'خصم الفاتورة', promotion_discount: 'خصم ترويجي', total_discount: 'إجمالي الخصم',
                balance: 'الرصيد', count: 'العدد', total: 'الإجمالي'
            };
            const column = (columns || []).find(function (col) { return col && col.key === key && col.label; });
            return column ? column.label : (map[key] || key);
        },

        formatReportValue(value, type) {
            if (value == null || value === '') return '';
            if (type === 'money') return this.money(value);
            if (type === 'int') return String(this.num(value));
            if (type === 'date') return this.dateOnly(value);
            return this.escapeHtml(value);
        },

        exportReportCsv() {
            if (!this.has('reports.export')) {
                this.toast('ليس لديك صلاحية تصدير التقارير.', 'warning');
                return;
            }
            const data = this.state.lastReport;
            if (!data) return;
            const columns = data.columns || [];
            const rows = data.rows || [];
            const escapeCell = function (value) {
                const text = String(value == null ? '' : value);
                return '"' + text.replace(/"/g, '""') + '"';
            };
            let csv = '\ufeff' + columns.map(function (c) { return escapeCell(c.label); }).join(',') + '\n';
            rows.forEach(function (row) {
                csv += columns.map(function (c) { return escapeCell(row[c.key]); }).join(',') + '\n';
            });
            this.downloadText('levi_report_' + (data.type || 'report') + '.csv', csv);
        },

        /* =================================================================
         * Users / roles / audit
         * ================================================================= */
        async loadUsers() {
            const data = await this.apiGet('users.list');
            if (!data) return;
            this.state.users = data.users || [];
            const self = this;
            this.renderTable('users-table', [
                { label: 'المستخدم', render: function (r) { return '<strong>' + self.escapeHtml(r.full_name) + '</strong><br><span class="muted">' + self.escapeHtml(r.username) + '</span>'; } },
                { label: 'الدور', render: function (r) { return self.escapeHtml(r.role_display_ar || r.role_name || ''); } },
                { label: 'الفرع', render: function (r) { return self.escapeHtml(r.branch_name || ''); } },
                { label: 'الهاتف', render: function (r) { return self.escapeHtml(r.phone || ''); } },
                { label: 'آخر دخول', render: function (r) { return r.last_login_at ? self.dateTime(r.last_login_at) : '-'; } },
                { label: 'الحالة', render: function (r) {
                    if (r.locked_until && new Date(r.locked_until.replace(' ', 'T')) > new Date()) {
                        return self.badge('مقفل مؤقتًا', 'red');
                    }
                    return self.badge(Number(r.is_active) === 1 ? 'نشط' : 'معطل', Number(r.is_active) === 1 ? 'green' : 'red');
                } },
                { label: 'إجراءات', render: function (r) {
                    let html = '<div style="display:flex;gap:4px;flex-wrap:wrap;">';
                    html += '<button class="btn btn-sm btn-outline" onclick="LeviPOS.openUserModal(' + r.id + ')">تعديل</button>';
                    html += '<button class="btn btn-sm btn-outline" onclick="LeviPOS.resetUserPassword(' + r.id + ')">كلمة المرور</button>';
                    if (r.locked_until) html += '<button class="btn btn-sm btn-success" onclick="LeviPOS.unlockUser(' + r.id + ')">فتح القفل</button>';
                    html += '<button class="btn btn-sm btn-danger" onclick="LeviPOS.deleteUser(' + r.id + ')">حذف</button>';
                    html += '</div>';
                    return html;
                } }
            ], this.state.users, 'لا يوجد مستخدمون.');
        },

        async openUserModal(id) {
            const results = await Promise.all([
                this.apiGet('users.roles'),
                this.apiGet('users.branches')
            ]);
            if (results[0]) this.state.roles = results[0].roles || [];
            if (results[1]) this.state.branches = results[1].branches || [];
            const user = id ? (this.state.users || []).find(function (u) { return Number(u.id) === Number(id); }) : null;
            if (id && !user) { this.toast('لم يتم العثور على المستخدم.', 'warning'); return; }
            const val = function (key, fallback) {
                return this.escapeHtml(user && user[key] != null ? user[key] : (fallback == null ? '' : fallback));
            }.bind(this);
            const body = '<div class="form-grid">'
                + '<div><label>اسم المستخدم *</label><input id="uf-username" class="form-control" value="' + val('username') + '" ' + (id ? 'disabled' : '') + '></div>'
                + '<div><label>الاسم الكامل *</label><input id="uf-fullname" class="form-control" value="' + val('full_name') + '"></div>'
                + '<div><label>الهاتف</label><input id="uf-phone" class="form-control" value="' + val('phone') + '"></div>'
                + '<div><label>البريد الإلكتروني</label><input type="email" id="uf-email" class="form-control" value="' + val('email') + '"></div>'
                + '<div><label>الدور *</label><select id="uf-role" class="form-select">' + this.options(this.state.roles, 'id', function (r) { return r.display_name_ar || r.name; }, user ? user.role_id : '', 'اختر الدور...') + '</select></div>'
                + '<div><label>الفرع *</label><select id="uf-branch" class="form-select">' + this.options(this.state.branches, 'id', 'name', user ? user.branch_id : (this.boot().branchId || ''), 'اختر الفرع...') + '</select></div>'
                + (id ? '' : '<div><label>كلمة المرور *</label><input type="password" id="uf-password" class="form-control" autocomplete="new-password"></div>')
                + '<div><label><input type="checkbox" id="uf-active" ' + (!user || Number(user.is_active) === 1 ? 'checked' : '') + '> نشط</label></div>'
                + '</div>';
            const footer = '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إلغاء</button>'
                + '<button type="button" class="btn btn-primary" onclick="LeviPOS.submitUser(' + (id || 'null') + ')">حفظ</button>';
            this.openModal(id ? 'تعديل المستخدم' : 'إضافة مستخدم', body, footer);
        },

        async submitUser(id) {
            const value = function (fieldId) { const el = document.getElementById(fieldId); return el ? el.value.trim() : ''; };
            const fullName = value('uf-fullname');
            const role = value('uf-role');
            const branch = value('uf-branch');
            if (!fullName || !role || !branch) { this.toast('الاسم والدور والفرع مطلوبة.', 'warning'); return; }
            const payload = {
                full_name: fullName,
                phone: value('uf-phone'),
                email: value('uf-email'),
                role_id: role,
                branch_id: branch,
                is_active: document.getElementById('uf-active') && document.getElementById('uf-active').checked ? 1 : 0
            };
            if (id) {
                payload.user_id = id;
            } else {
                const username = value('uf-username');
                const password = value('uf-password');
                if (!username || !password) { this.toast('اسم المستخدم وكلمة المرور مطلوبان.', 'warning'); return; }
                payload.username = username;
                payload.password = password;
            }
            const result = await this.apiPost(id ? 'users.update' : 'users.create', payload);
            if (!result) return;
            this.toast(id ? 'تم تحديث المستخدم.' : 'تمت إضافة المستخدم.', 'success');
            this.closeModal();
            this.loadUsers();
        },

        async resetUserPassword(id) {
            const password = window.prompt('كلمة المرور الجديدة (8 أحرف على الأقل):');
            if (!password) return;
            if (password.length < 8) { this.toast('كلمة المرور يجب أن تكون 8 أحرف على الأقل.', 'warning'); return; }
            const result = await this.apiPost('users.reset_password', { user_id: id, new_password: password });
            if (!result) return;
            this.toast('تم إعادة تعيين كلمة المرور.', 'success');
            this.loadUsers();
        },

        async unlockUser(id) {
            const result = await this.apiPost('users.unlock', { user_id: id });
            if (!result) return;
            this.toast('تم فتح قفل المستخدم.', 'success');
            this.loadUsers();
        },

        async deleteUser(id) {
            if (!window.confirm('هل تريد حذف/أرشفة هذا المستخدم؟')) return;
            const result = await this.apiPost('users.delete', { user_id: id });
            if (!result) return;
            this.toast('تمت العملية.', 'success');
            this.loadUsers();
        },

        async loadRoles() {
            const data = await this.apiGet('users.roles');
            if (!data) return;
            this.state.roles = data.roles || [];
            const self = this;
            this.renderTable('roles-table', [
                { label: 'الدور', render: function (r) { return '<strong>' + self.escapeHtml(r.display_name_ar || r.name) + '</strong><br><span class="muted">' + self.escapeHtml(r.name) + '</span>'; } },
                { label: 'الوصف', render: function (r) { return self.escapeHtml(r.description || ''); } },
                { label: 'عدد المستخدمين', render: function (r) { return self.num(r.users_count); } },
                { label: 'إجراءات', render: function (r) {
                    if (r.name === 'admin') return self.badge('كل الصلاحيات', 'blue');
                    if (r.name === 'owner' || Number(r.is_hidden) === 1) return self.badge('دور محمي', 'red');
                    return '<button class="btn btn-sm btn-outline" onclick="LeviPOS.openRolePermissions(' + r.id + ')">تعديل الصلاحيات</button>';
                } }
            ], this.state.roles, 'لا توجد أدوار.');
        },

        async openRolePermissions(roleId) {
            const results = await Promise.all([
                this.apiGet('users.permissions'),
                this.apiGet('users.role_permissions', { role_id: roleId })
            ]);
            if (!results[0] || !results[1]) return;
            const permissions = results[0].permissions || [];
            const granted = results[1].permissions || [];
            const grantedMap = {};
            granted.forEach(function (code) { grantedMap[code] = true; });
            const groups = {};
            permissions.forEach(function (perm) {
                if (!groups[perm.module]) groups[perm.module] = [];
                groups[perm.module].push(perm);
            });
            const moduleLabels = {
                dashboard: 'لوحة التحكم', pos: 'نقطة البيع', products: 'الأدوية والمنتجات', inventory: 'المخزون',
                purchases: 'المشتريات', suppliers: 'شركات الأدوية والموردون', customers: 'المرضى والعملاء',
                invoices: 'الفواتير', returns: 'المرتجعات', batches: 'دفعات الأدوية',
                expiry: 'انتهاء الصلاحية', patients: 'ملفات المرضى', doctors: 'الأطباء',
                prescriptions: 'الروشتات', expenses: 'المصروفات', cash_register: 'الصندوق',
                reports: 'التقارير', users: 'المستخدمون', backups: 'النسخ الاحتياطي',
                audit: 'سجل التدقيق', settings: 'الإعدادات'
            };
            const self = this;
            let html = '<div class="muted mb-2" style="font-size:12px;">حدد الصلاحيات المسموح بها لهذا الدور.</div>';
            Object.keys(groups).forEach(function (module) {
                html += '<div class="card mb-2" style="padding:10px;"><strong style="font-size:13px;">' + self.escapeHtml(moduleLabels[module] || module) + '</strong>'
                    + '<div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:8px;">';
                groups[module].forEach(function (perm) {
                    html += '<label style="display:inline-flex;align-items:center;gap:5px;font-size:12px;">'
                        + '<input type="checkbox" class="perm-check" value="' + self.escapeHtml(perm.code) + '"' + (grantedMap[perm.code] ? ' checked' : '') + '> '
                        + self.escapeHtml(perm.display_name_ar || perm.code) + '</label>';
                });
                html += '</div></div>';
            });
            const footer = '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إلغاء</button>'
                + '<button type="button" class="btn btn-primary" onclick="LeviPOS.saveRolePermissions(' + roleId + ')">حفظ الصلاحيات</button>';
            this.openModal('صلاحيات الدور', html, footer, 'wide');
        },

        async saveRolePermissions(roleId) {
            const codes = [];
            document.querySelectorAll('.perm-check:checked').forEach(function (el) { codes.push(el.value); });
            const result = await this.apiPost('users.update_role_permissions', { role_id: roleId, permissions: codes });
            if (!result) return;
            this.toast('تم تحديث صلاحيات الدور.', 'success');
            this.closeModal();
            this.loadRoles();
        },

        async loadAuditLogs() {
            const start = document.getElementById('audit-start');
            const end = document.getElementById('audit-end');
            const q = document.getElementById('audit-q');
            const data = await this.apiGet('users.audit_logs', {
                start_date: start ? start.value : '',
                end_date: end ? end.value : '',
                q: q ? q.value.trim() : '',
                limit: 400
            });
            if (!data) return;
            const self = this;
            this.renderTable('audit-table', [
                { label: 'التاريخ', render: function (r) { return self.dateTime(r.created_at); } },
                { label: 'المستخدم', render: function (r) { return self.escapeHtml(r.user_name || ''); } },
                { label: 'الإجراء', render: function (r) { return '<strong>' + self.escapeHtml(r.action_label || self.actionLabel(r.action_type)) + '</strong>'; } },
                { label: 'الوحدة', render: function (r) { return self.escapeHtml(r.entity_label || r.entity_type || ''); } },
                { label: 'الوصف', render: function (r) { return self.escapeHtml(r.description || ''); } },
                { label: 'IP', render: function (r) { return self.escapeHtml(r.ip_address || ''); } }
            ], data.logs || [], 'لا توجد سجلات تدقيق في الفترة المحددة.');
        },

        /* =================================================================
         * Backups
         * ================================================================= */
        async loadBackups() {
            const data = await this.apiGet('backups.list');
            if (!data) return;
            const self = this;
            this.renderTable('backups-table', [
                { label: 'الملف', render: function (r) { return '<strong>' + self.escapeHtml(r.file_name) + '</strong>'; } },
                { label: 'النوع', render: function (r) { return self.statusLabel(r.backup_type); } },
                { label: 'الحجم', render: function (r) { return self.formatBytes(r.file_size_bytes); } },
                { label: 'الموقع', render: function (r) { return self.statusLabel(r.storage_location); } },
                { label: 'التاريخ', render: function (r) { return self.dateTime(r.created_at); } },
                { label: 'الحالة', render: function (r) { return self.statusBadge(r.status); } },
                { label: 'المستخدم', render: function (r) { return self.escapeHtml(r.created_by_name || ''); } },
                { label: 'إجراءات', render: function (r) {
                    return '<div style="display:flex;gap:4px;flex-wrap:wrap;">'
                        + '<button class="btn btn-sm btn-outline" onclick="LeviPOS.downloadBackup(' + r.id + ')">تنزيل</button>'
                        + '<button class="btn btn-sm btn-warning" onclick="LeviPOS.restoreBackup(' + r.id + ')">استعادة</button>'
                        + '<button class="btn btn-sm btn-danger" onclick="LeviPOS.deleteBackup(' + r.id + ')">حذف</button>'
                        + '</div>';
                } }
            ], data.backups || [], 'لا توجد نسخ احتياطية.');
        },

        downloadBackup(id) {
            if (!id) return;
            window.location.href = 'download_backup.php?backup_id=' + encodeURIComponent(id);
        },

        formatBytes(bytes) {
            const value = this.num(bytes);
            if (value < 1024) return value + ' B';
            if (value < 1048576) return (value / 1024).toFixed(1) + ' KB';
            if (value < 1073741824) return (value / 1048576).toFixed(2) + ' MB';
            return (value / 1073741824).toFixed(2) + ' GB';
        },

        openBackupModal() {
            const body = '<div class="form-grid">'
                + '<div><label>مكان التخزين</label><select id="bk-location" class="form-select"><option value="local">المجلد المحلي الافتراضي</option><option value="usb">وحدة USB</option><option value="external_disk">قرص خارجي</option></select></div>'
                + '<div><label>مسار مخصص (اختياري)</label><input id="bk-path" class="form-control" placeholder="مثال: E:\\LEVI_BACKUPS"></div>'
                + '<div><label>ملاحظات</label><input id="bk-notes" class="form-control"></div>'
                + '</div>'
                + '<div class="muted mt-2" style="font-size:12px;">يتم إنشاء نسخة كاملة من قاعدة البيانات. تأكد من وجود مساحة كافية.</div>';
            const footer = '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إلغاء</button>'
                + '<button type="button" class="btn btn-primary" onclick="LeviPOS.submitBackup()">إنشاء النسخة</button>';
            this.openModal('إنشاء نسخة احتياطية', body, footer);
        },

        async submitBackup() {
            const result = await this.apiPost('backups.create', {
                storage_location: document.getElementById('bk-location').value,
                custom_path: document.getElementById('bk-path').value.trim(),
                notes: document.getElementById('bk-notes').value.trim()
            });
            if (!result) return;
            this.toast('تم إنشاء النسخة الاحتياطية.', 'success');
            this.closeModal();
            this.loadBackups();
        },

        async restoreBackup(id) {
            if (!window.confirm('تحذير: استعادة النسخة الاحتياطية ستستبدل البيانات الحالية بالكامل. هل تريد المتابعة؟')) return;
            const result = await this.apiPost('backups.restore', { backup_id: id });
            if (!result) return;
            this.toast('تمت استعادة النسخة الاحتياطية. سيتم إعادة تحميل الصفحة.', 'success');
            setTimeout(function () { window.location.reload(); }, 1500);
        },

        async deleteBackup(id) {
            if (!window.confirm('هل تريد حذف ملف النسخة الاحتياطية نهائيًا؟')) return;
            const result = await this.apiPost('backups.delete', { backup_id: id });
            if (!result) return;
            this.toast('تم حذف النسخة الاحتياطية.', 'success');
            this.loadBackups();
        },

        /* =================================================================
         * Settings
         * ================================================================= */
        async loadSettings() {
            const data = await this.apiGet('settings.get');
            if (!data) return;
            const flat = {};
            const grouped = data.settings || {};
            Object.keys(grouped).forEach(function (group) {
                const values = grouped[group] || {};
                Object.keys(values).forEach(function (key) { flat[key] = values[key]; });
            });
            this.state.settings = flat;
            this.state.settingsCategories = data.categories || [];
            this.state.settingsBrands = data.brands || [];
            this.state.settingsBranches = data.branches || [];
            this.state.settingsRegisters = data.cash_registers || [];
            this.state.expenseCategories = data.expense_categories || [];
            this.state.categories = data.categories || [];
            this.state.brands = data.brands || [];
            this.state.settingsLoaded = true;
            this.renderSettingsStore();
            this.renderSettingsPharmacy();
            this.renderSettingsCategories();
            this.renderSettingsBrands();
            this.renderSettingsBranches();
            this.renderSettingsRegisters();
            this.renderSettingsExpenseCategories();
        },

        renderSettingsStore() {
            const container = document.getElementById('settings-store-form');
            if (!container) return;
            const s = this.state.settings || {};
            const self = this;
            const val = function (key, fallback) { return self.escapeHtml(s[key] != null ? s[key] : (fallback == null ? '' : fallback)); };
            const check = function (key, label, locked) {
                const checked = locked || String(s[key] != null ? s[key] : '0') === '1';
                return '<label style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;background:var(--levi-surface-2);padding:7px 10px;border-radius:8px;border:1px solid var(--levi-border);">'
                    + '<input type="checkbox" data-st-check="' + key + '"' + (checked ? ' checked' : '') + (locked ? ' disabled' : '') + '> '
                    + self.escapeHtml(label) + '</label>';
            };
            let html = '<div class="form-grid">';
            if (this.boot() && this.boot().isOwner) {
                html += '<div style="grid-column:1/-1;"><strong style="font-size:13px;">هوية الصيدلية وصفحة الدخول (للمالك فقط)</strong></div>'
                    + '<div><label>اسم الصيدلية</label><input class="form-control" data-st-key="store_name" value="' + val('store_name') + '"></div>'
                    + '<div><label>هاتف الصيدلية</label><input class="form-control" data-st-key="store_phone" value="' + val('store_phone') + '"></div>'
                    + '<div><label>عنوان الصيدلية</label><input class="form-control" data-st-key="store_address" value="' + val('store_address') + '"></div>'
                    + '<div><label>عنوان صفحة الدخول</label><input class="form-control" data-st-key="login_title" value="' + val('login_title', 'منظومة إدارة الصيدلية') + '"></div>'
                    + '<div><label>وصف صفحة الدخول</label><input class="form-control" data-st-key="login_subtitle" value="' + val('login_subtitle', 'نظام محلي متكامل دون إنترنت (Local Offline System)') + '"></div>'
                    + '<div style="grid-column:1/-1;"><label>شعار الصيدلية</label>'
                    + '<div class="logo-uploader">'
                    + '<img id="store-logo-preview" class="store-logo-preview" src="' + this.escapeHtml(this.boot().logoUrl || 'assets/images/levi-logo.svg') + '" alt="شعار الصيدلية">'
                    + '<div class="logo-uploader-actions">'
                    + '<input type="file" id="store-logo-file" class="form-control" accept="image/png,image/jpeg,image/webp,image/svg+xml">'
                    + '<div class="toolbar" style="margin:0;">'
                    + '<button type="button" class="btn btn-primary btn-sm" onclick="LeviPOS.uploadStoreLogo()">رفع الشعار</button>'
                    + '<button type="button" class="btn btn-outline btn-sm" onclick="LeviPOS.resetStoreLogo()">استعادة الشعار الافتراضي</button>'
                    + '</div>'
                    + '<div class="muted" style="font-size:11px;">PNG أو JPG أو WEBP أو SVG — بحد أقصى 2 ميغابايت. يظهر الشعار في الشريط الجانبي وصفحة تسجيل الدخول.</div>'
                    + '</div></div></div>';
            }
            html += '<div><label>العملة الأساسية</label><input class="form-control" data-st-key="base_currency" value="' + val('base_currency', 'LYD') + '"></div>'
                + '<div><label>طريقة التكلفة</label><select class="form-select" data-st-key="costing_method">'
                + '<option value="weighted_average"' + (s.costing_method === 'weighted_average' ? ' selected' : '') + '>متوسط مرجح</option>'
                + '<option value="fifo"' + (s.costing_method === 'fifo' ? ' selected' : '') + '>FIFO (الوارد أولًا صادر أولًا)</option>'
                + '</select></div>'
                + '<div><label>أقصى نسبة خصم %</label><input type="number" step="0.01" min="0" max="100" class="form-control" data-st-key="max_discount_percent" value="' + val('max_discount_percent', '20') + '"></div>'
                + '<div><label>عرض الإيصال (مم)</label><input type="number" min="40" max="210" class="form-control" data-st-key="receipt_width_mm" value="' + val('receipt_width_mm', '80') + '"><div class="muted" style="font-size:11px;">58 أو 80 للطابعات الحرارية، و210 لفواتير A4.</div></div>'
                + '<div><label>عدد النسخ الاحتياطية المحفوظة</label><input type="number" min="1" max="365" class="form-control" data-st-key="backup_retention_count" value="' + val('backup_retention_count', '10') + '"></div>'
                + '<div><label>مهلة الجلسة (دقيقة)</label><input type="number" min="5" max="1440" class="form-control" data-st-key="session_idle_timeout_minutes" value="' + val('session_idle_timeout_minutes', '30') + '"></div>'
                + '<div><label>أقصى محاولات دخول</label><input type="number" min="1" max="50" class="form-control" data-st-key="max_login_attempts" value="' + val('max_login_attempts', '5') + '"></div>'
                + '<div><label>مدة القفل (دقيقة)</label><input type="number" min="1" max="1440" class="form-control" data-st-key="lockout_minutes" value="' + val('lockout_minutes', '15') + '"></div>'
                + '<div><label>حد المخزون الافتراضي</label><input type="number" min="0" class="form-control" data-st-key="default_min_stock" value="' + val('default_min_stock', '5') + '"></div>'
                + '<div><label>المنطقة الزمنية</label><select class="form-select" data-st-key="timezone">'
                + ['Africa/Tripoli', 'Africa/Cairo', 'Africa/Tunis', 'Asia/Riyadh', 'Asia/Dubai', 'UTC'].map(function (tz) {
                    return '<option value="' + tz + '"' + (s.timezone === tz ? ' selected' : '') + '>' + tz + '</option>';
                }).join('')
                + '</select></div>'
                + '<div><label>صيغة التاريخ</label><input class="form-control" data-st-key="date_format" value="' + val('date_format', 'Y-m-d H:i') + '"></div>'
                + '<div><label>بادئة رقم الفاتورة</label><input class="form-control" data-st-key="invoice_prefix" value="' + val('invoice_prefix', 'INV') + '"></div>'
                + '<div><label>نسبة الضريبة (%)</label><input type="number" step="0.001" min="0" max="100" class="form-control" data-st-key="tax_rate" value="' + val('tax_rate', '0') + '"></div>'
                + '<div><label>طريقة حساب الضريبة</label><select class="form-select" data-st-key="tax_inclusive">'
                + '<option value="0"' + (String(s.tax_inclusive) === '1' ? '' : ' selected') + '>حصرية (تضاف فوق الإجمالي)</option>'
                + '<option value="1"' + (String(s.tax_inclusive) === '1' ? ' selected' : '') + '>شاملة (مستخرجة من الإجمالي)</option>'
                + '</select></div>'
                + '<div><label>الرقم الضريبي للصيدلية</label><input class="form-control" data-st-key="tax_number" value="' + val('tax_number') + '"></div>'
                + '<div><label>الحد الأقصى لائتمان العميل</label><input type="number" step="0.01" min="0" class="form-control" data-st-key="max_credit_per_customer" value="' + val('max_credit_per_customer', '0') + '"></div>'
                + '</div>'
                + '<div class="mt-3" style="border-top:1px solid var(--levi-border);padding-top:10px;">'
                + '<strong style="font-size:13px;">الطابعات ومقاسات الورق</strong>'
                + '<div class="muted" style="font-size:11px;">الكشف يقرأ الطابعات المثبتة على جهاز الخادم (ويندوز) ومقاس الورق المسجل في تعريفها. اختيار الطابعة نفسها يبقى من نافذة الطباعة في المتصفح.</div>'
                + '<div class="toolbar" style="margin-top:8px;"><button type="button" class="btn btn-outline btn-sm" onclick="LeviPOS.detectPrinters()">كشف الطابعات تلقائيًا</button></div>'
                + '<div id="printer-detect-results" class="mt-2"></div>'
                + '<details class="mt-2" style="font-size:11.5px;"><summary style="cursor:pointer;">طريقة ضبط الطابعة في نافذة الطباعة</summary>'
                + '<ul style="margin:8px 18px 0 0;padding:0;line-height:1.7;">'
                + '<li>حرارية 58mm: اجعل عرض الإيصال 58، وفي نافذة الطباعة اختر ورق 58mm والهوامش «بلا» والمقياس 100%.</li>'
                + '<li>حرارية 80mm: اجعل عرض الإيصال 80، واختر ورق 80mm والهوامش «بلا» والمقياس 100%.</li>'
                + '<li>ليزر/نافثة A4: اجعل عرض الإيصال 210، وسيطبع كصفحة A4 بهوامش 8mm.</li>'
                + '<li>أزل خيار «رؤوس وتذييلات الصفحة» من إعدادات الطباعة في المتصفح.</li>'
                + '<li>المتصفح لا يختار الطابعة تلقائيًا؛ اختر الطابعة من نافذة الطباعة، ويمكن جعلها افتراضية في ويندوز.</li>'
                + '</ul></details>'
                + '</div>'
                + '<div class="mt-3"><label>رأس الإيصال</label><textarea class="form-control" rows="2" data-st-key="receipt_header">' + val('receipt_header') + '</textarea></div>'
                + '<div class="mt-2"><label>تذييل الإيصال</label><textarea class="form-control" rows="2" data-st-key="receipt_footer">' + val('receipt_footer') + '</textarea></div>'
                + '<div class="mt-3" style="display:flex;flex-wrap:wrap;gap:8px;">'
                + check('allow_discounts', 'تفعيل الخصومات')
                + check('allow_negative_stock_sale', 'السماح بالبيع بالسالب')
                + check('allow_stock_adjustments', 'تفعيل تسويات المخزون')
                + check('enable_credit_sales', 'تفعيل البيع الآجل')
                + check('enable_partial_payment', 'تفعيل الدفع الجزئي')
                + check('enable_tax', 'تفعيل ضريبة المبيعات')
                + check('enable_low_stock_alerts', 'تنبيهات المخزون المنخفض')
                + check('enable_cash_register_closing', 'إلزام إغلاق الوردية')
                + check('require_open_register', 'إلزام فتح وردية قبل البيع')
                + check('enable_customer_credit_limit', 'تفعيل حد الائتمان للعملاء')
                + check('auto_print_receipt', 'طباعة الإيصال تلقائيًا')
                + check('auto_backup_enabled', 'نسخ احتياطي تلقائي')
                + check('open_cash_drawer_on_cash', 'فتح درج النقد عند الدفع النقدي')
                + '</div>'
                + '<div class="muted mt-2" style="font-size:11px;">«إلزام إغلاق الوردية»: عند تفعيله يمنع تسجيل الخروج والوردية مفتوحة. عند إيقافه يُسمح بتسجيل الخروج وتبقى الوردية مفتوحة حتى تُغلق لاحقًا.</div>'
                + '<button type="button" class="btn btn-primary mt-3" onclick="LeviPOS.saveStoreSettings()">حفظ الإعدادات</button>';
            container.innerHTML = html;
        },

        async saveStoreSettings() {
            const map = {};
            document.querySelectorAll('[data-st-key]').forEach(function (el) {
                map[el.getAttribute('data-st-key')] = el.value;
            });
            document.querySelectorAll('[data-st-check]').forEach(function (el) {
                map[el.getAttribute('data-st-check')] = el.checked ? '1' : '0';
            });
            // Persist the user's choice for the shift requirement.
            map.require_open_register = map.require_open_register === '1' ? '1' : '0';
            const result = await this.apiPost('settings.update', { settings: map });
            if (!result) return;
            if (this.boot()) {
                this.boot().requireOpenRegister = map.require_open_register === '1';
                this.boot().enforceShiftClosing = map.enable_cash_register_closing === '1';
                this.boot().allowNegativeStock = map.allow_negative_stock_sale === '1' || (this.boot().user && (this.boot().user.role_name === 'admin' || this.boot().user.role_name === 'owner'));
                if (map.base_currency) this.boot().baseCurrency = map.base_currency;
                this.boot().allowDiscounts = map.allow_discounts === '1';
                this.boot().enableCreditSales = map.enable_credit_sales === '1';
                this.boot().enablePartialPayment = map.enable_partial_payment === '1';
                this.boot().expiryAlertDays = Number(map.expiry_alert_days || 30);
                this.boot().blockExpiredSales = map.block_expired_sales === '1';
                this.boot().fefoEnabled = map.fefo_enabled === '1';
                this.boot().requirePrescription = map.require_prescription_for_flagged === '1';
                this.boot().enableLowStockAlerts = map.enable_low_stock_alerts === '1';
                this.boot().allowStockAdjustments = map.allow_stock_adjustments === '1';
                this.boot().autoPrintReceipt = map.auto_print_receipt === '1';
                this.boot().defaultMinStock = Number(map.default_min_stock || 5);
                this.boot().taxEnabled = map.enable_tax === '1';
                this.boot().taxRate = Number(map.tax_rate || 0);
                this.boot().taxInclusive = map.tax_inclusive === '1';
                this.boot().taxNumber = map.tax_number || '';
            }
            const autoPrintEl = document.getElementById('pos-auto-print');
            if (autoPrintEl) autoPrintEl.checked = map.auto_print_receipt === '1';
            this.toast('تم حفظ الإعدادات.', 'success');
            this.loadSettings();
        },

        async detectPrinters() {
            const box = document.getElementById('printer-detect-results');
            if (!box) return;
            box.innerHTML = '<span class="muted">جارٍ كشف الطابعات المثبتة على الخادم...</span>';
            const data = await this.apiGet('printers.detect');
            if (!data) {
                box.innerHTML = '<span class="muted">تعذر الاتصال بخدمة كشف الطابعات.</span>';
                return;
            }
            if (!data.supported) {
                box.innerHTML = '<div class="muted" style="font-size:12px;">' + this.escapeHtml(data.reason || 'الكشف التلقائي غير متاح على هذا الجهاز.') + '</div>';
                return;
            }
            const printers = Array.isArray(data.printers) ? data.printers : [];
            if (!printers.length) {
                box.innerHTML = '<div class="muted" style="font-size:12px;">' + this.escapeHtml(data.reason || 'لم يتم العثور على طابعات مثبتة.') + '</div>';
                return;
            }
            let html = '<div class="muted" style="font-size:11px;">تم العثور على ' + printers.length + ' طابعة. اختر مقاس الورق المناسب ثم اضغط «حفظ الإعدادات».</div>';
            printers.forEach(function (printer) {
                const isVirtual = printer.virtual === true || printer.kind === 'virtual';
                const width = printer.width_mm ? Number(printer.width_mm) : null;
                const paper = printer.paper ? String(printer.paper) : 'المقاس غير معروف';
                const kind = isVirtual ? 'غير فيزيائية' : (printer.kind === 'thermal' ? 'حرارية' : (printer.kind === 'sheet' ? 'ورق مسطح' : 'غير محددة'));
                const badge = printer.is_default ? ' <span class="pill pill-gray">الافتراضية على الخادم</span>' : '';
                html += '<div style="display:flex;flex-wrap:wrap;gap:8px;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px dashed var(--levi-border);">'
                    + '<div style="min-width:170px;"><strong>' + this.escapeHtml(printer.name) + '</strong>' + badge
                    + '<div class="muted" style="font-size:10.5px;">' + this.escapeHtml(printer.driver || '') + (printer.port ? ' — ' + this.escapeHtml(printer.port) : '') + '</div></div>'
                    + '<div style="font-size:11px;">' + this.escapeHtml(paper) + ' — ' + this.escapeHtml(kind) + (width ? ' — ' + width + 'mm' : '') + '</div>'
                    + (width && !isVirtual ? '<button type="button" class="btn btn-sm btn-outline" onclick="LeviPOS.applyPrinterWidth(' + width + ')">استخدام ' + width + 'mm</button>' : '')
                    + '</div>';
            }, this);
            if (!printers.some(function (p) { return p.virtual !== true && p.kind !== 'virtual'; })) {
                html += '<div class="muted" style="font-size:11px;margin-top:8px;">ملاحظة: هذه طابعات وهمية فقط (PDF/Fax/OneNote). ثبّت تعريف الطابعة الحرارية في ويندوز ثم أعد الكشف.</div>';
            }
            box.innerHTML = html;
        },

        applyPrinterWidth(width) {
            const el = document.querySelector('[data-st-key="receipt_width_mm"]');
            if (!el) return;
            el.value = String(width);
            this.toast('تم ضبط عرض الإيصال على ' + width + 'mm. اضغط حفظ الإعدادات للتطبيق.', 'success');
        },

        applyStoreLogo(url) {
            if (!url) return;
            if (this.boot()) this.boot().logoUrl = url;
            const brandLogo = document.querySelector('.sidebar-logo');
            if (brandLogo) brandLogo.src = url;
            const preview = document.getElementById('store-logo-preview');
            if (preview) preview.src = url;
        },

        async uploadStoreLogo() {
            if (!this.boot().isOwner) {
                this.toast('رفع شعار الصيدلية مخصص للمدير العام.', 'warning');
                return;
            }
            const input = document.getElementById('store-logo-file');
            if (!input || !input.files || !input.files.length) {
                this.toast('اختر ملف الشعار أولاً.', 'warning');
                return;
            }
            const form = new FormData();
            form.append('logo', input.files[0]);
            const result = await this.apiUpload('settings.logo_upload', form);
            if (!result) return;
            this.applyStoreLogo(result.logo_url);
            this.toast('تم رفع الشعار وتطبيقه بنجاح.', 'success');
            this.loadSettings();
        },

        async resetStoreLogo() {
            if (!this.boot().isOwner) {
                this.toast('تعديل شعار الصيدلية مخصص للمدير العام.', 'warning');
                return;
            }
            if (!window.confirm('سيتم حذف الشعار المخصص واستعادة الشعار الافتراضي. هل تريد المتابعة؟')) {
                return;
            }
            const result = await this.apiPost('settings.logo_reset', {});
            if (!result) return;
            this.applyStoreLogo(result.logo_url);
            this.toast('تمت استعادة الشعار الافتراضي.', 'success');
            this.loadSettings();
        },

        /* =================================================================
         * Owner cleanup panel (destructive maintenance)
         * ================================================================= */
        collectOwnerGroups() {
            const boxes = document.querySelectorAll('[data-owner-group]');
            if (!boxes.length) return null;
            const groups = [];
            boxes.forEach(function (box) {
                if (box.checked) groups.push(box.getAttribute('data-owner-group'));
            });
            return groups;
        },

        ownerCleanupFiltersFromDom() {
            const start = document.getElementById('owner-cleanup-start');
            const end = document.getElementById('owner-cleanup-end');
            const all = document.getElementById('owner-cleanup-all-branches');
            const stored = this.state.ownerCleanupFilters || {};
            return {
                start_date: start ? start.value : (stored.start_date || ''),
                end_date: end ? end.value : (stored.end_date || ''),
                all_branches: all ? !!all.checked : !!stored.all_branches
            };
        },

        async loadOwnerCleanupPanel() {
            const container = document.getElementById('owner-cleanup-panel');
            if (!container) return;
            if (!this.boot().isOwner) {
                container.innerHTML = '<div class="card"><div class="empty-state">هذه الأدوات مخصصة للمدير العام فقط.</div></div>';
                return;
            }
            const previousGroups = this.collectOwnerGroups();
            if (previousGroups !== null) this.state.ownerCleanupGroups = previousGroups;
            const filters = this.ownerCleanupFiltersFromDom();
            this.state.ownerCleanupFilters = filters;
            if (!container.innerHTML.trim()) {
                container.innerHTML = '<div class="card"><div class="empty-state">جارٍ تحميل ملخص السجلات…</div></div>';
            }
            const data = await this.apiGet('owner.cleanup_summary', {
                all_branches: filters.all_branches ? 1 : 0,
                start_date: filters.start_date,
                end_date: filters.end_date
            });
            if (!data) return;
            this.state.ownerCleanupLabels = data.labels || {};
            this.renderOwnerCleanupPanel(data);
        },

        renderOwnerCleanupPanel(data) {
            const container = document.getElementById('owner-cleanup-panel');
            if (!container || !data) return;
            const self = this;
            const filters = this.state.ownerCleanupFilters || { start_date: '', end_date: '', all_branches: false };
            const labels = data.labels || {};
            const counts = data.groups || {};
            const selected = this.state.ownerCleanupGroups || Object.keys(labels);
            const esc = function (value) { return self.escapeHtml(value); };
            let rows = '';
            Object.keys(labels).forEach(function (key) {
                const checked = selected.indexOf(key) !== -1;
                rows += '<tr>'
                    + '<td><input type="checkbox" data-owner-group="' + esc(key) + '"' + (checked ? ' checked' : '') + '></td>'
                    + '<td>' + esc(labels[key]) + '</td>'
                    + '<td><strong>' + self.num(counts[key]) + '</strong></td>'
                    + '</tr>';
            });
            const html = ''
                + '<div class="card owner-cleanup">'
                + '<div class="owner-cleanup-head">'
                + '<div><h3>أدوات المدير العام — الحذف النهائي</h3>'
                + '<p class="muted">حذف نهائي للمجموعات المحددة دون إمكانية التراجع. يمكنك الآن حذف سجل التدقيق وسجل الورديات اختياريًا من القائمة أدناه. لا يمكن حذف الورديات أثناء وجود وردية مفتوحة.</p></div>'
                + '<button type="button" class="btn btn-outline btn-sm" onclick="LeviPOS.loadOwnerCleanupPanel()">تحديث الملخص</button>'
                + '</div>'
                + '<div class="owner-cleanup-filters">'
                + '<div><label>من تاريخ</label><input type="date" id="owner-cleanup-start" class="form-control" value="' + esc(filters.start_date) + '"></div>'
                + '<div><label>إلى تاريخ</label><input type="date" id="owner-cleanup-end" class="form-control" value="' + esc(filters.end_date) + '"></div>'
                + '<label class="owner-cleanup-check"><input type="checkbox" id="owner-cleanup-all-branches"' + (filters.all_branches ? ' checked' : '') + '> شمل جميع الفروع</label>'
                + '</div>'
                + '<div class="table-responsive"><table class="table table-sm"><thead><tr><th style="width:44px;"></th><th>المجموعة</th><th>عدد السجلات</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
                + '<div class="owner-cleanup-stats">'
                + '<span>إجمالي السجلات المطابقة للفلتر: <strong>' + self.num(data.total) + '</strong></span>'
                + '<span>سجلات التدقيق المخزنة: <strong>' + self.num(data.audit_logs) + '</strong></span>'
                + '<span>عملاء عليهم ديون: <strong>' + self.num(data.customers_with_debt) + '</strong></span>'
                + '<span>موردون عليهم أرصدة: <strong>' + self.num(data.suppliers_with_balance) + '</strong></span>'
                + '</div>'
                + '<div class="owner-cleanup-confirm">'
                + '<label>اكتب العبارة <code>حذف نهائي</code> لتأكيد العملية</label>'
                + '<input type="text" id="owner-cleanup-confirm" class="form-control" placeholder="حذف نهائي" autocomplete="off">'
                + '</div>'
                + '<div class="owner-cleanup-actions">'
                + '<button type="button" class="btn btn-danger" onclick="LeviPOS.purgeOwnerCleanup()">حذف نهائي للسجلات المحددة</button>'
                + '</div></div>';
            container.innerHTML = html;
        },

        async purgeOwnerCleanup() {
            if (!this.boot().isOwner) {
                this.toast('هذه الأداة مخصصة للمدير العام فقط.', 'warning');
                return;
            }
            const groups = this.collectOwnerGroups() || [];
            const filters = this.ownerCleanupFiltersFromDom();
            const confirmEl = document.getElementById('owner-cleanup-confirm');
            const confirmation = confirmEl ? confirmEl.value.trim() : '';
            if (!groups.length) {
                this.toast('اختر مجموعة واحدة على الأقل للحذف.', 'warning');
                return;
            }
            if (confirmation !== 'حذف نهائي') {
                this.toast('عبارة التأكيد غير صحيحة. اكتب «حذف نهائي» بالضبط.', 'error');
                return;
            }
            const sensitive = groups.indexOf('audit_logs') !== -1 || groups.indexOf('shift_logs') !== -1;
            const extraWarning = sensitive
                ? '\n\nتنبيه: اخترت حذف سجل التدقيق و/أو سجل الورديات، وسيتم إزالتهما نهائيًا.'
                : '';
            if (!window.confirm('سيتم حذف السجلات المحددة نهائيًا ولا يمكن التراجع.' + extraWarning + '\nهل أنت متأكد من تنفيذ العملية؟')) {
                return;
            }
            const result = await this.apiPost('owner.cleanup_purge', {
                groups: groups,
                start_date: filters.start_date,
                end_date: filters.end_date,
                all_branches: filters.all_branches ? 1 : 0,
                confirmation: confirmation
            });
            if (!result) return;
            this.toast('تم الحذف النهائي. إجمالي السجلات المحذوفة: ' + this.num(result.total_deleted) + '.', 'success');
            this.state.ownerCleanupGroups = null;
            this.loadOwnerCleanupPanel();
        },

        renderSettingsPharmacy() {
            const container = document.getElementById('settings-pharmacy-form');
            if (!container) return;
            const s = this.state.settings || {};
            const val = function (key, fallback) { return this.escapeHtml(s[key] != null ? s[key] : (fallback == null ? '' : fallback)); }.bind(this);
            const check = function (key, label, checkedByDefault) {
                const checked = String(s[key] != null ? s[key] : (checkedByDefault ? '1' : '0')) === '1';
                return '<label style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;background:var(--levi-surface-2);padding:7px 10px;border-radius:8px;border:1px solid var(--levi-border);">'
                    + '<input type="checkbox" data-st-check="' + key + '"' + (checked ? ' checked' : '') + '> '
                    + this.escapeHtml(label) + '</label>';
            }.bind(this);
            container.innerHTML = '<div class="form-grid">'
                + '<div><label>نافذة تنبيه الانتهاء (يوم)</label><input type="number" min="1" max="365" class="form-control" data-st-key="expiry_alert_days" value="' + val('expiry_alert_days', '30') + '"></div>'
                + '</div>'
                + '<div class="mt-3" style="display:flex;flex-wrap:wrap;gap:8px;">'
                + check('block_expired_sales', 'منع بيع الأدوية المنتهية', true)
                + check('fefo_enabled', 'صرف الدفعات الأقرب للانتهاء أولاً (FEFO)', true)
                + check('require_prescription_for_flagged', 'إلزام الروشتة للأدوية المحددّة', false)
                + '</div>'
                + '<div class="muted mt-2" style="font-size:11px;">تُستخدم نافذة التنبيه في لوحة التحكم وشاشة انتهاء الصلاحية. المنع وFEFO يُطبّقان فور حفظ الإعدادات.</div>'
                + '<button type="button" class="btn btn-primary mt-3" onclick="LeviPOS.saveStoreSettings()">حفظ كل الإعدادات</button>';
        },

        renderSettingsCategories() {
            const categories = this.state.settingsCategories || [];
            const self = this;
            this.renderTable('settings-categories-table', [
                { label: 'التصنيف', render: function (r) { return '<strong>' + self.escapeHtml(r.name) + '</strong>'; } },
                { label: 'الوصف', render: function (r) { return self.escapeHtml(r.description || ''); } },
                { label: 'الحالة', render: function (r) { return self.badge(Number(r.is_active) === 1 ? 'نشط' : 'معطل', Number(r.is_active) === 1 ? 'green' : 'red'); } },
                { label: 'إجراءات', render: function (r) {
                    return '<div class="table-actions">'
                        + '<button class="btn btn-sm btn-outline" onclick="LeviPOS.openCategoryModal(' + r.id + ')">تعديل</button>'
                        + '<button class="btn btn-sm btn-danger" onclick="LeviPOS.deleteSettingItem(\'category\', ' + r.id + ')">حذف نهائي</button>'
                        + '</div>';
                } }
            ], categories, 'لا توجد تصنيفات.');
        },

        openCategoryModal(id) {
            const category = id ? (this.state.settingsCategories || []).find(function (c) { return Number(c.id) === Number(id); }) : null;
            const val = function (key, fallback) {
                return this.escapeHtml(category && category[key] != null ? category[key] : (fallback == null ? '' : fallback));
            }.bind(this);
            const body = '<div class="form-grid">'
                + '<div><label>اسم التصنيف *</label><input id="catf-name" class="form-control" value="' + val('name') + '"></div>'
                + '<div><label>الوصف</label><input id="catf-description" class="form-control" value="' + val('description') + '"></div>'
                + '<div><label><input type="checkbox" id="catf-active" ' + (!category || Number(category.is_active) === 1 ? 'checked' : '') + '> نشط</label></div>'
                + '</div>';
            const footer = '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إلغاء</button>'
                + '<button type="button" class="btn btn-primary" onclick="LeviPOS.submitCategory(' + (id || 'null') + ')">حفظ</button>';
            this.openModal(id ? 'تعديل التصنيف' : 'إضافة تصنيف', body, footer);
        },

        async submitCategory(id) {
            const value = function (fieldId) { const el = document.getElementById(fieldId); return el ? el.value.trim() : ''; };
            const name = value('catf-name');
            if (!name) { this.toast('اسم التصنيف مطلوب.', 'warning'); return; }
            const payload = {
                name: name,
                description: value('catf-description'),
                is_active: document.getElementById('catf-active') && document.getElementById('catf-active').checked ? 1 : 0
            };
            if (id) payload.id = id;
            const result = await this.apiPost('settings.category_save', payload);
            if (!result) return;
            this.toast('تم حفظ التصنيف.', 'success');
            this.closeModal();
            this.loadSettings();
        },

        renderSettingsBrands() {
            const brands = this.state.settingsBrands || [];
            const self = this;
            this.renderTable('settings-brands-table', [
                { label: 'الماركة', render: function (r) { return '<strong>' + self.escapeHtml(r.name) + '</strong>'; } },
                { label: 'البلد', render: function (r) { return self.escapeHtml(r.country || ''); } },
                { label: 'الوصف', render: function (r) { return self.escapeHtml(r.description || ''); } },
                { label: 'الحالة', render: function (r) { return self.badge(Number(r.is_active) === 1 ? 'نشطة' : 'معطلة', Number(r.is_active) === 1 ? 'green' : 'red'); } },
                { label: 'إجراءات', render: function (r) {
                    return '<div class="table-actions">'
                        + '<button class="btn btn-sm btn-outline" onclick="LeviPOS.openBrandModal(' + r.id + ')">تعديل</button>'
                        + '<button class="btn btn-sm btn-danger" onclick="LeviPOS.deleteSettingItem(\'brand\', ' + r.id + ')">حذف نهائي</button>'
                        + '</div>';
                } }
            ], brands, 'لا توجد ماركات.');
        },

        openBrandModal(id) {
            const brand = id ? (this.state.settingsBrands || []).find(function (b) { return Number(b.id) === Number(id); }) : null;
            const val = function (key, fallback) {
                return this.escapeHtml(brand && brand[key] != null ? brand[key] : (fallback == null ? '' : fallback));
            }.bind(this);
            const body = '<div class="form-grid">'
                + '<div><label>اسم الماركة *</label><input id="brf-name" class="form-control" value="' + val('name') + '"></div>'
                + '<div><label>البلد</label><input id="brf-country" class="form-control" value="' + val('country') + '"></div>'
                + '<div><label>الوصف</label><input id="brf-description" class="form-control" value="' + val('description') + '"></div>'
                + '<div><label><input type="checkbox" id="brf-active" ' + (!brand || Number(brand.is_active) === 1 ? 'checked' : '') + '> نشطة</label></div>'
                + '</div>';
            const footer = '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إلغاء</button>'
                + '<button type="button" class="btn btn-primary" onclick="LeviPOS.submitBrand(' + (id || 'null') + ')">حفظ</button>';
            this.openModal(id ? 'تعديل الماركة' : 'إضافة ماركة', body, footer);
        },

        async submitBrand(id) {
            const value = function (fieldId) { const el = document.getElementById(fieldId); return el ? el.value.trim() : ''; };
            const name = value('brf-name');
            if (!name) { this.toast('اسم الماركة مطلوب.', 'warning'); return; }
            const payload = {
                name: name,
                country: value('brf-country'),
                description: value('brf-description'),
                is_active: document.getElementById('brf-active') && document.getElementById('brf-active').checked ? 1 : 0
            };
            if (id) payload.id = id;
            const result = await this.apiPost('settings.brand_save', payload);
            if (!result) return;
            this.toast('تم حفظ الماركة.', 'success');
            this.closeModal();
            this.loadSettings();
        },

        renderSettingsBranches() {
            const branches = this.state.settingsBranches || [];
            const self = this;
            this.renderTable('settings-branches-table', [
                { label: 'الفرع', render: function (r) { return '<strong>' + self.escapeHtml(r.name) + '</strong>' + (Number(r.is_main) === 1 ? ' ' + self.badge('رئيسي', 'blue') : ''); } },
                { label: 'الرمز', render: function (r) { return self.escapeHtml(r.code || ''); } },
                { label: 'العنوان', render: function (r) { return self.escapeHtml(r.address || ''); } },
                { label: 'الهاتف', render: function (r) { return self.escapeHtml(r.phone || ''); } },
                { label: 'الحالة', render: function (r) { return self.badge(Number(r.is_active) === 1 ? 'نشط' : 'معطل', Number(r.is_active) === 1 ? 'green' : 'red'); } },
                { label: 'إجراءات', render: function (r) {
                    return '<div class="table-actions">'
                        + '<button class="btn btn-sm btn-outline" onclick="LeviPOS.openBranchModal(' + r.id + ')">تعديل</button>'
                        + '<button class="btn btn-sm btn-danger" onclick="LeviPOS.deleteSettingItem(\'branch\', ' + r.id + ')">حذف نهائي</button>'
                        + '</div>';
                } }
            ], branches, 'لا توجد فروع.');
        },

        openBranchModal(id) {
            const branch = id ? (this.state.settingsBranches || []).find(function (b) { return Number(b.id) === Number(id); }) : null;
            const val = function (key, fallback) {
                return this.escapeHtml(branch && branch[key] != null ? branch[key] : (fallback == null ? '' : fallback));
            }.bind(this);
            const body = '<div class="form-grid">'
                + '<div><label>اسم الفرع *</label><input id="bnf-name" class="form-control" value="' + val('name') + '"></div>'
                + '<div><label>رمز الفرع *</label><input id="bnf-code" class="form-control" value="' + val('code') + '"></div>'
                + '<div><label>العنوان</label><input id="bnf-address" class="form-control" value="' + val('address') + '"></div>'
                + '<div><label>الهاتف</label><input id="bnf-phone" class="form-control" value="' + val('phone') + '"></div>'
                + '<div><label><input type="checkbox" id="bnf-active" ' + (!branch || Number(branch.is_active) === 1 ? 'checked' : '') + '> نشط</label></div>'
                + '</div>';
            const footer = '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إلغاء</button>'
                + '<button type="button" class="btn btn-primary" onclick="LeviPOS.submitBranch(' + (id || 'null') + ')">حفظ</button>';
            this.openModal(id ? 'تعديل الفرع' : 'إضافة فرع', body, footer);
        },

        async submitBranch(id) {
            const value = function (fieldId) { const el = document.getElementById(fieldId); return el ? el.value.trim() : ''; };
            const name = value('bnf-name');
            const code = value('bnf-code');
            if (!name || !code) { this.toast('اسم الفرع ورمزه مطلوبان.', 'warning'); return; }
            const payload = {
                name: name,
                code: code,
                address: value('bnf-address'),
                phone: value('bnf-phone'),
                is_active: document.getElementById('bnf-active') && document.getElementById('bnf-active').checked ? 1 : 0
            };
            if (id) payload.id = id;
            const result = await this.apiPost('settings.branch_save', payload);
            if (!result) return;
            this.toast('تم حفظ الفرع.', 'success');
            this.closeModal();
            this.loadSettings();
        },

        renderSettingsRegisters() {
            const registers = this.state.settingsRegisters || [];
            const self = this;
            this.renderTable('settings-registers-table', [
                { label: 'الصندوق', render: function (r) { return '<strong>' + self.escapeHtml(r.name) + '</strong>'; } },
                { label: 'الرمز', render: function (r) { return self.escapeHtml(r.code || ''); } },
                { label: 'الحالة', render: function (r) { return self.badge(Number(r.is_active) === 1 ? 'نشط' : 'معطل', Number(r.is_active) === 1 ? 'green' : 'red'); } },
                { label: 'إجراءات', render: function (r) {
                    return '<div class="table-actions">'
                        + '<button class="btn btn-sm btn-outline" onclick="LeviPOS.openRegisterModal(' + r.id + ')">تعديل</button>'
                        + '<button class="btn btn-sm btn-danger" onclick="LeviPOS.deleteSettingItem(\'cash_register\', ' + r.id + ')">حذف نهائي</button>'
                        + '</div>';
                } }
            ], registers, 'لا توجد صناديق معرفة.');
        },

        openRegisterModal(id) {
            const register = id ? (this.state.settingsRegisters || []).find(function (r) { return Number(r.id) === Number(id); }) : null;
            const val = function (key, fallback) {
                return this.escapeHtml(register && register[key] != null ? register[key] : (fallback == null ? '' : fallback));
            }.bind(this);
            const body = '<div class="form-grid">'
                + '<div><label>اسم الصندوق *</label><input id="rgf-name" class="form-control" value="' + val('name') + '"></div>'
                + '<div><label>رمز الصندوق *</label><input id="rgf-code" class="form-control" value="' + val('code') + '"></div>'
                + '<div><label><input type="checkbox" id="rgf-active" ' + (!register || Number(register.is_active) === 1 ? 'checked' : '') + '> نشط</label></div>'
                + '</div>';
            const footer = '<button type="button" class="btn btn-outline" onclick="LeviPOS.closeModal()">إلغاء</button>'
                + '<button type="button" class="btn btn-primary" onclick="LeviPOS.submitRegister(' + (id || 'null') + ')">حفظ</button>';
            this.openModal(id ? 'تعديل الصندوق' : 'إضافة صندوق', body, footer);
        },

        async submitRegister(id) {
            const value = function (fieldId) { const el = document.getElementById(fieldId); return el ? el.value.trim() : ''; };
            const name = value('rgf-name');
            const code = value('rgf-code');
            if (!name || !code) { this.toast('اسم الصندوق ورمزه مطلوبان.', 'warning'); return; }
            const payload = {
                name: name,
                code: code,
                is_active: document.getElementById('rgf-active') && document.getElementById('rgf-active').checked ? 1 : 0
            };
            if (id) payload.id = id;
            const result = await this.apiPost('settings.cash_register_save', payload);
            if (!result) return;
            this.toast('تم حفظ الصندوق.', 'success');
            this.closeModal();
            this.loadSettings();
        },

        renderSettingsExpenseCategories() {
            const categories = this.state.expenseCategories || [];
            const self = this;
            this.renderTable('settings-expense-categories-table', [
                { label: 'التصنيف', render: function (r) { return '<strong>' + self.escapeHtml(r.name) + '</strong>'; } },
                { label: 'الوصف', render: function (r) { return self.escapeHtml(r.description || ''); } },
                { label: 'الحالة', render: function (r) { return self.badge(Number(r.is_active) === 1 ? 'نشط' : 'معطل', Number(r.is_active) === 1 ? 'green' : 'red'); } },
                { label: 'إجراءات', render: function (r) {
                    return '<div class="table-actions">'
                        + '<button class="btn btn-sm btn-outline" onclick="LeviPOS.openExpenseCategoryForm(' + r.id + ')">تعديل</button>'
                        + '<button class="btn btn-sm btn-danger" onclick="LeviPOS.deleteSettingItem(\'expense_category\', ' + r.id + ')">حذف نهائي</button>'
                        + '</div>';
                } }
            ], categories, 'لا توجد تصنيفات مصروفات.');
        },

        async deleteSettingItem(kind, id) {
            const labels = {
                category: 'التصنيف',
                brand: 'الماركة',
                branch: 'الفرع',
                cash_register: 'الصندوق',
                expense_category: 'تصنيف المصروف'
            };
            const actions = {
                category: 'settings.category_delete',
                brand: 'settings.brand_delete',
                branch: 'settings.branch_delete',
                cash_register: 'settings.cash_register_delete',
                expense_category: 'settings.expense_category_delete'
            };
            if (!actions[kind]) {
                this.toast('نوع السجل المطلوب حذفه غير صالح.', 'error');
                return;
            }
            if (!window.confirm('سيتم حذف ' + (labels[kind] || 'السجل') + ' نهائيًا من النظام.\nإذا كان مرتبطًا بسجلات أخرى فسيتم منع الحذف.\nهل أنت متأكد؟')) {
                return;
            }
            const result = await this.apiPost(actions[kind], { id: id });
            if (!result) return;
            this.toast('تم حذف ' + (labels[kind] || 'السجل') + ' نهائيًا.', 'success');
            this.loadSettings();
        },

        /* =================================================================
         * Advanced pharmacy: stocktakes, damage, units, patients,
         * prescription review and supply forecasting.
         * ================================================================= */
        pharmacyActiveTab() {
            const active = document.querySelector('#view-pharmacy .tab-btn.active');
            return active && active.dataset ? (active.dataset.tab || 'stocktake') : 'stocktake';
        },

        pharmacyProductId(selectId) {
            const el = document.getElementById(selectId);
            if (!el || !el.value) return 0;
            return parseInt(String(el.value).split('|')[0], 10) || 0;
        },

        async loadPharmacy() {
            const meta = await this.apiGet('pharmacy.meta');
            if (meta) this.state.pharmacyMeta = meta;
            const productSelect = document.getElementById('pharmacy-unit-product');
            if (productSelect && productSelect.options.length <= 1) {
                await this.loadProductOptions('pharmacy-unit-product');
            }
            const priceSelect = document.getElementById('pharmacy-price-product');
            if (priceSelect && priceSelect.options.length <= 1) {
                await this.loadProductOptions('pharmacy-price-product');
            }
            this.onTab('pharmacy', this.pharmacyActiveTab());
        },

        pharmacyStatus(text) {
            const value = String(text || '');
            if (value === 'approved' || value === 'received' || value === 'resolved' || value === 'dispensed') {
                return this.badge('معتمد', 'green');
            }
            if (value === 'counted' || value === 'sent' || value === 'confirmed' || value === 'partially_received') {
                return this.badge('قيد المعالجة', 'amber');
            }
            if (value === 'cancelled' || value === 'rejected') {
                return this.badge('ملغي', 'red');
            }
            return this.badge(value || 'مسودة', 'gray');
        },

        async loadPharmacyStocktake() {
            const data = await this.apiGet('stocktake.list');
            if (!data) return;
            const self = this;
            this.renderTable('pharmacy-stocktake-table', [
                { label: 'الرقم', render: function (r) { return '<strong>' + self.escapeHtml(r.session_number || '') + '</strong>'; } },
                { label: 'التاريخ', render: function (r) { return self.escapeHtml(r.created_at || ''); } },
                { label: 'الأصناف', render: function (r) { return self.num(r.item_count); } },
                { label: 'الحالة', render: function (r) { return self.pharmacyStatus(r.status); } },
                { label: 'إجراءات', render: function (r) {
                    let html = '<button class="btn btn-sm btn-outline" onclick="LeviPOS.openStocktakeModal(' + r.id + ')">تفاصيل/عد</button>';
                    if (r.status !== 'approved') {
                        html += ' <button class="btn btn-sm btn-primary" onclick="LeviPOS.approveStocktake(' + r.id + ')">اعتماد</button>';
                    }
                    return html;
                } }
            ], data.sessions || [], 'لا توجد جلسات جرد بعد.');
        },

        async openStocktakeModal(id) {
            const sessionId = parseInt(id || '0', 10) || 0;
            if (!sessionId) {
                const body = '<div class="form-group"><label for="stkf-notes">ملاحظات</label>'
                    + '<textarea id="stkf-notes" class="form-control" rows="3"></textarea></div>'
                    + '<label class="checkbox"><input type="checkbox" id="stkf-batches"> تضمين الدفعات في الجرد</label>';
                const footer = '<button type="button" class="btn btn-primary" onclick="LeviPOS.submitStocktake()">إنشاء الجلسة</button>';
                this.openModal('جرد فعلي جديد', body, footer);
                return;
            }
            const data = await this.apiGet('stocktake.get', { session_id: sessionId });
            if (!data) return;
            const self = this;
            const items = data.items || [];
            let rows = '';
            items.forEach(function (item) {
                const counted = item.counted_qty === null || item.counted_qty === undefined
                    ? item.expected_qty : item.counted_qty;
                rows += '<tr><td>' + self.escapeHtml(item.product_name || item.product_id)
                    + '</td><td>' + self.num(item.expected_qty) + '</td><td>'
                    + '<input type="number" min="0" step="0.01" class="form-control" id="stk-count-'
                    + item.id + '" value="' + self.escapeHtml(counted) + '"></td></tr>';
            });
            const body = '<div class="table-responsive"><table class="table table-sm"><thead><tr>'
                + '<th>الدواء</th><th>الرصيد الدفتري</th><th>العد الفعلي</th></tr></thead><tbody>'
                + (rows || '<tr><td colspan="3">لا توجد أصناف في هذه الجلسة.</td></tr>')
                + '</tbody></table></div>';
            const footer = '<button type="button" class="btn btn-primary" onclick="LeviPOS.saveStocktakeCounts(' + sessionId + ')">حفظ العد</button> '
                + '<button type="button" class="btn btn-success" onclick="LeviPOS.approveStocktake(' + sessionId + ')">اعتماد الجرد</button>';
            this.openModal('جلسة الجرد ' + (data.session_number || sessionId), body, footer, 'wide');
        },

        async submitStocktake() {
            const notes = document.getElementById('stkf-notes');
            const batches = document.getElementById('stkf-batches');
            const result = await this.apiPost('stocktake.create', {
                notes: notes ? notes.value : '',
                include_batches: batches && batches.checked ? 1 : 0
            });
            if (!result) return;
            this.toast('تم إنشاء جلسة الجرد.', 'success');
            this.closeModal();
            this.loadPharmacyStocktake();
        },

        async saveStocktakeCounts(sessionId) {
            const inputs = document.querySelectorAll('#modal-box input[id^="stk-count-"]');
            const items = [];
            inputs.forEach(function (input) {
                const itemId = parseInt(String(input.id).replace('stk-count-', ''), 10);
                if (itemId > 0) items.push({ item_id: itemId, counted_qty: input.value });
            });
            if (!items.length) {
                this.toast('أدخل العد الفعلي لصنف واحد على الأقل.', 'error');
                return;
            }
            const result = await this.apiPost('stocktake.count', { session_id: sessionId, items: items });
            if (!result) return;
            this.toast('تم حفظ العد الفعلي.', 'success');
            this.closeModal();
            this.loadPharmacyStocktake();
        },

        async approveStocktake(sessionId) {
            if (!window.confirm('سيتم اعتماد الجرد وتطبيق فروق المخزون. هل أنت متأكد؟')) return;
            const result = await this.apiPost('stocktake.approve', { session_id: sessionId });
            if (!result) return;
            this.toast(result.already_approved ? 'هذه الجلسة معتمدة مسبقًا.' : 'تم اعتماد الجرد وتطبيق الفروق.', 'success');
            this.closeModal();
            this.loadPharmacyStocktake();
        },

        async loadPharmacyDamage() {
            const data = await this.apiGet('damage.list');
            if (!data) return;
            const self = this;
            this.renderTable('pharmacy-damage-table', [
                { label: 'الرقم', render: function (r) { return self.escapeHtml(r.record_number || ''); } },
                { label: 'الدواء', render: function (r) { return self.escapeHtml(r.product_name || r.product_id); } },
                { label: 'الكمية', render: function (r) { return self.num(r.quantity); } },
                { label: 'الحالة', render: function (r) { return self.pharmacyStatus(r.status); } },
                { label: 'التاريخ', render: function (r) { return self.escapeHtml(r.created_at || ''); } },
                { label: 'إجراءات', render: function (r) {
                    return '<button class="btn btn-sm btn-outline" onclick="LeviPOS.updateDamageStatus(' + r.id + ', \'resolved\')">إغلاق</button> '
                        + '<button class="btn btn-sm btn-danger" onclick="LeviPOS.updateDamageStatus(' + r.id + ', \'disposed\')">إتلاف</button>';
                } }
            ], data.records || [], 'لا توجد سجلات تلف.');
        },

        async openDamageModal() {
            const products = await this.ensureProductCatalog();
            const body = '<div class="form-group"><label for="dmf-product">الدواء *</label>'
                + '<select id="dmf-product" class="form-select">' + this.productOptionHtml(products, '') + '</select></div>'
                + '<div class="form-group"><label for="dmf-quantity">الكمية *</label>'
                + '<input type="number" id="dmf-quantity" class="form-control" min="1" step="1" value="1"></div>'
                + '<div class="form-group"><label for="dmf-reason">السبب</label>'
                + '<input type="text" id="dmf-reason" class="form-control" placeholder="انتهاء صلاحية / تلف أثناء النقل..."></div>'
                + '<div class="form-group"><label for="dmf-notes">ملاحظات</label>'
                + '<textarea id="dmf-notes" class="form-control" rows="2"></textarea></div>';
            const footer = '<button type="button" class="btn btn-primary" onclick="LeviPOS.submitDamage()">تسجيل التلف</button>';
            this.openModal('تسجيل دواء تالف', body, footer);
        },

        async submitDamage() {
            const productId = this.pharmacyProductId('dmf-product');
            const quantity = parseInt((document.getElementById('dmf-quantity') || {}).value || '0', 10) || 0;
            if (!productId || quantity <= 0) {
                this.toast('اختر الدواء وأدخل كمية صحيحة.', 'error');
                return;
            }
            const reason = document.getElementById('dmf-reason');
            const notes = document.getElementById('dmf-notes');
            const result = await this.apiPost('damage.create', {
                product_id: productId,
                quantity: quantity,
                reason: reason ? reason.value : '',
                notes: notes ? notes.value : '',
                condition: 'damaged',
                action_taken: 'quarantine'
            });
            if (!result) return;
            this.toast('تم تسجيل الدواء التالف.', 'success');
            this.closeModal();
            this.loadPharmacyDamage();
        },

        async updateDamageStatus(id, status) {
            const result = await this.apiPost('damage.status', { record_id: id, status: status });
            if (!result) return;
            this.toast('تم تحديث حالة السجل.', 'success');
            this.loadPharmacyDamage();
        },

        async loadPharmacyUnits() {
            const productId = this.pharmacyProductId('pharmacy-unit-product');
            if (!productId) {
                this.renderTable('pharmacy-units-table', [], [], 'اختر دواءً لعرض وحداته.');
                return;
            }
            const data = await this.apiGet('product_units.list', { product_id: productId });
            if (!data) return;
            const self = this;
            this.renderTable('pharmacy-units-table', [
                { label: 'الوحدة', render: function (r) { return '<strong>' + self.escapeHtml(r.unit_name || '') + '</strong>'; } },
                { label: 'معامل التحويل', render: function (r) { return self.num(r.conversion_factor); } },
                { label: 'سعر الوحدة', render: function (r) { return self.num(r.selling_price); } },
                { label: 'افتراضية', render: function (r) { return Number(r.is_default) === 1 ? self.badge('نعم', 'green') : '-'; } },
                { label: 'الحالة', render: function (r) { return Number(r.is_active) === 1 ? self.badge('نشطة', 'green') : self.badge('معطلة', 'red'); } }
            ], data.units || [], 'لا توجد وحدات إضافية لهذا الدواء.');
        },

        async openUnitModal() {
            const productId = this.pharmacyProductId('pharmacy-unit-product');
            if (!productId) {
                this.toast('اختر الدواء أولًا.', 'error');
                return;
            }
            const data = await this.apiGet('product_units.list', { product_id: productId });
            if (!data) return;
            const units = data.units || [];
            const self = this;
            let rows = '';
            for (let i = 0; i < Math.max(3, units.length); i++) {
                const unit = units[i] || {};
                rows += '<tr><td><input class="form-control" id="uf-name-' + i + '" value="' + self.escapeHtml(unit.unit_name || '') + '" placeholder="اسم الوحدة"></td>'
                    + '<td><input type="number" min="1" step="0.01" class="form-control" id="uf-factor-' + i + '" value="' + self.escapeHtml(unit.conversion_factor || 1) + '"></td>'
                    + '<td><input type="number" min="0" step="0.01" class="form-control" id="uf-price-' + i + '" value="' + self.escapeHtml(unit.selling_price || 0) + '"></td>'
                    + '<td><input type="checkbox" id="uf-default-' + i + '"' + (Number(unit.is_default) === 1 ? ' checked' : '') + '></td></tr>';
            }
            const body = '<div class="table-responsive"><table class="table table-sm"><thead><tr>'
                + '<th>اسم الوحدة</th><th>معامل التحويل للوحدة الأساسية</th><th>السعر</th><th>افتراضية</th>'
                + '</tr></thead><tbody>' + rows + '</tbody></table></div>';
            const footer = '<button type="button" class="btn btn-primary" onclick="LeviPOS.submitUnits(' + productId + ')">حفظ الوحدات</button>';
            this.openModal('وحدات الدواء', body, footer, 'wide');
        },

        async submitUnits(productId) {
            const units = [];
            for (let i = 0; i < 20; i++) {
                const nameEl = document.getElementById('uf-name-' + i);
                if (!nameEl) break;
                const unitName = String(nameEl.value || '').trim();
                if (unitName === '') continue;
                const factorEl = document.getElementById('uf-factor-' + i);
                const priceEl = document.getElementById('uf-price-' + i);
                const defaultEl = document.getElementById('uf-default-' + i);
                units.push({
                    unit_name: unitName,
                    conversion_factor: factorEl ? factorEl.value : 1,
                    selling_price: priceEl ? priceEl.value : 0,
                    is_default: defaultEl && defaultEl.checked ? 1 : 0,
                    is_active: 1
                });
            }
            if (!units.length) {
                this.toast('أدخل وحدة واحدة على الأقل.', 'error');
                return;
            }
            const result = await this.apiPost('product_units.save', { product_id: productId, units: units });
            if (!result) return;
            this.toast('تم حفظ وحدات الدواء.', 'success');
            this.closeModal();
            this.loadPharmacyUnits();
        },

        async loadPharmacyPatients() {
            const search = document.getElementById('pharmacy-patient-search');
            const data = await this.apiGet('patients.search', { q: search ? search.value.trim() : '' });
            if (!data) return;
            const self = this;
            this.renderTable('pharmacy-patients-table', [
                { label: 'المريض', render: function (r) { return '<strong>' + self.escapeHtml(r.full_name || r.name || '') + '</strong>'; } },
                { label: 'الهاتف', render: function (r) { return self.escapeHtml(r.phone || ''); } },
                { label: 'الحساسية', render: function (r) { return self.escapeHtml(r.drug_allergies || r.allergies || ''); } },
                { label: 'الأمراض المزمنة', render: function (r) { return self.escapeHtml(r.chronic_diseases || ''); } },
                { label: 'إجراءات', render: function (r) {
                    return '<button class="btn btn-sm btn-outline" onclick="LeviPOS.openPatientFile(' + r.id + ')">الملف الطبي</button>';
                } }
            ], data.patients || [], 'لا توجد نتائج مطابقة.');
        },

        async openPatientFile(patientId) {
            const data = await this.apiGet('patients.file', { patient_id: patientId });
            if (!data) return;
            const self = this;
            const history = data.medication_history || [];
            let rows = '';
            history.forEach(function (item) {
                rows += '<tr><td>' + self.escapeHtml(item.prescription_number || '')
                    + '</td><td>' + self.escapeHtml(item.product_name || '')
                    + '</td><td>' + self.escapeHtml(item.dose || '')
                    + '</td><td>' + self.escapeHtml(item.prescription_date || '') + '</td></tr>';
            });
            const body = '<div class="form-grid">'
                + '<div><label>المريض</label><div><strong>' + self.escapeHtml(data.full_name || data.name || '') + '</strong></div></div>'
                + '<div><label>العمر</label><div>' + self.num(data.age) + '</div></div>'
                + '<div><label>الحساسية للأدوية</label><input id="pf-allergies" class="form-control" value="' + self.escapeHtml(data.drug_allergies || data.allergies || '') + '"></div>'
                + '<div><label>الأمراض المزمنة</label><input id="pf-chronic" class="form-control" value="' + self.escapeHtml(data.chronic_diseases || '') + '"></div>'
                + '<div><label>ملاحظات طبية</label><textarea id="pf-notes" class="form-control" rows="2">' + self.escapeHtml(data.medical_notes || '') + '</textarea></div>'
                + '</div>'
                + '<h4>سجل الأدوية</h4><div class="table-responsive"><table class="table table-sm"><thead><tr>'
                + '<th>الروشتة</th><th>الدواء</th><th>الجرعة</th><th>التاريخ</th></tr></thead><tbody>'
                + (rows || '<tr><td colspan="4">لا يوجد سجل دوائي.</td></tr>') + '</tbody></table></div>';
            const footer = '<button type="button" class="btn btn-primary" onclick="LeviPOS.savePatientMedical(' + patientId + ')">حفظ الملف الطبي</button>';
            this.openModal('الملف الطبي للمريض', body, footer, 'wide');
        },

        async savePatientMedical(patientId) {
            const allergies = document.getElementById('pf-allergies');
            const chronic = document.getElementById('pf-chronic');
            const notes = document.getElementById('pf-notes');
            const result = await this.apiPost('patients.update_medical', {
                patient_id: patientId,
                drug_allergies: allergies ? allergies.value : '',
                chronic_diseases: chronic ? chronic.value : '',
                medical_notes: notes ? notes.value : ''
            });
            if (!result) return;
            this.toast('تم تحديث الملف الطبي.', 'success');
            this.closeModal();
            this.loadPharmacyPatients();
        },

        pharmacyPrescriptionId() {
            const el = document.getElementById('pharmacy-review-id');
            return parseInt((el || {}).value || '0', 10) || 0;
        },

        renderPharmacyReview(data) {
            const el = document.getElementById('pharmacy-review-result');
            if (!el) return;
            if (!data) {
                el.innerHTML = '<div class="empty-state">تعذر الحصول على نتيجة المراجعة.</div>';
                return;
            }
            const self = this;
            const rx = data.prescription || data;
            const warnings = data.warnings || data.interaction_warnings || [];
            const stockAlerts = data.stock_alerts || [];
            let html = '<div class="form-grid">'
                + '<div><label>رقم الروشتة</label><div>' + self.escapeHtml(rx.prescription_number || '') + '</div></div>'
                + '<div><label>المريض</label><div>' + self.escapeHtml(rx.patient_name || data.patient_name || '') + '</div></div>'
                + '<div><label>الطبيب</label><div>' + self.escapeHtml(rx.doctor_name || data.doctor_name || '') + '</div></div>'
                + '<div><label>الحالة</label><div>' + self.pharmacyStatus(rx.status || data.status) + '</div></div>'
                + '</div>';
            const items = data.items || [];
            if (items.length) {
                html += '<div class="table-responsive"><table class="table table-sm"><thead><tr>'
                    + '<th>الدواء</th><th>الكمية</th><th>الجرعة</th><th>التعليمات</th></tr></thead><tbody>';
                items.forEach(function (item) {
                    html += '<tr><td>' + self.escapeHtml(item.product_name || '')
                        + '</td><td>' + self.num(item.quantity)
                        + '</td><td>' + self.escapeHtml(item.dose || '')
                        + '</td><td>' + self.escapeHtml(item.dosage_instructions || '') + '</td></tr>';
                });
                html += '</tbody></table></div>';
            }
            if (warnings.length) {
                html += '<h4>تحذيرات التداخل</h4><ul class="pgx-list">';
                warnings.forEach(function (w) {
                    html += '<li>' + self.escapeHtml(typeof w === 'string' ? w : (w.message || w.description || JSON.stringify(w))) + '</li>';
                });
                html += '</ul>';
            }
            if (stockAlerts.length) {
                html += '<h4>تنبيهات المخزون والانتهاء</h4><ul class="pgx-list">';
                stockAlerts.forEach(function (a) {
                    html += '<li>' + self.escapeHtml(a.product_name || a.name || '') + ': '
                        + self.escapeHtml(a.message || a.status || '') + '</li>';
                });
                html += '</ul>';
            }
            if (data.review_notes) {
                html += '<h4>ملاحظات المراجعة</h4><p>' + self.escapeHtml(data.review_notes) + '</p>';
            }
            el.innerHTML = html;
        },

        loadPharmacyReview() {
            document.getElementById('pharmacy-review-result').innerHTML = '<div class="empty-state">أدخل رقم الروشتة ثم اضغط «مراجعة الروشتة».</div>';
        },

        async reviewPrescription() {
            const id = this.pharmacyPrescriptionId();
            if (!id) { this.toast('أدخل رقم الروشتة أولًا.', 'error'); return; }
            this.renderPharmacyReview(await this.apiGet('prescriptions.review', { prescription_id: id }));
        },

        async posPrescriptionSummary() {
            const id = this.pharmacyPrescriptionId();
            if (!id) { this.toast('أدخل رقم الروشتة أولًا.', 'error'); return; }
            this.renderPharmacyReview(await this.apiGet('prescriptions.pos_summary', { prescription_id: id }));
        },

        async uploadPrescriptionImage() {
            const id = this.pharmacyPrescriptionId();
            const input = document.getElementById('pharmacy-rx-image');
            if (!id || !input || !input.files || !input.files.length) {
                this.toast('اختر رقم الروشتة وصورة صالحة.', 'error');
                return;
            }
            const form = new FormData();
            form.append('prescription_id', String(id));
            form.append('image', input.files[0]);
            const result = await this.apiUpload('prescriptions.image_upload', form);
            if (!result) return;
            this.toast('تم رفع صورة الروشتة.', 'success');
        },

        async ocrPrescription() {
            const self = this;
            const input = document.getElementById('pharmacy-rx-image');
            if (!input || !input.files || !input.files.length) {
                this.toast('اختر صورة الروشتة أولًا.', 'error');
                return;
            }
            const form = new FormData();
            form.append('image', input.files[0]);
            const result = await this.apiUpload('prescriptions.ocr', form);
            if (!result) return;
            const text = result.raw_text || result.extracted_text || result.text || '';
            const suggestions = result.suggestions || [];
            let suggestionHtml = '';
            if (suggestions.length) {
                suggestionHtml = '<h4>مقترحات مستخرجة</h4><ul class="pgx-list">';
                suggestions.forEach(function (s) {
                    suggestionHtml += '<li>' + self.escapeHtml(typeof s === 'string' ? s : JSON.stringify(s)) + '</li>';
                });
                suggestionHtml += '</ul>';
            }
            const el = document.getElementById('pharmacy-review-result');
            if (el) {
                el.innerHTML = '<h4>نتيجة القراءة</h4><pre>' + this.escapeHtml(text || 'لم يتم استخراج نص واضح.')
                    + '</pre>' + suggestionHtml + '<p class="muted">' + this.escapeHtml(result.message || '')
                    + ' راجع النتيجة وأدخل البيانات يدويًا في الروشتة عند الحاجة.</p>';
            }
            this.toast(result.available === false ? 'محرك OCR غير متاح؛ الإدخال اليدوي مطلوب.' : 'تمت قراءة الصورة.', result.available === false ? 'error' : 'success');
        },

        async loadPharmacySupply() {
            const productId = this.pharmacyProductId('pharmacy-price-product');
            const requests = [
                this.apiGet('purchase_orders.list'),
                this.apiGet('pharmacy.forecast', { days: 30 }),
                this.apiGet('supplier_alerts.summary')
            ];
            requests.push(productId
                ? this.apiGet('prices.compare_suppliers', { product_id: productId, days: 365 })
                : Promise.resolve(null));
            const results = await Promise.all(requests);
            const self = this;

            const prices = results[3];
            this.renderTable('pharmacy-prices-table', [
                { label: 'المورد', render: function (r) { return self.escapeHtml(r.supplier_name || r.supplier_company || ''); } },
                { label: 'أقل سعر', render: function (r) { return self.num(r.min_unit_cost); } },
                { label: 'متوسط السعر', render: function (r) { return self.num(r.avg_unit_cost); } },
                { label: 'أعلى سعر', render: function (r) { return self.num(r.max_unit_cost); } },
                { label: 'عدد المشتريات', render: function (r) { return self.num(r.purchase_lines); } }
            ], prices ? (prices.suppliers || []) : [], 'اختر دواءً لمقارنة أسعار الموردين.');

            const orders = results[0];
            this.renderTable('pharmacy-orders-table', [
                { label: 'الرقم', render: function (r) { return self.escapeHtml(r.order_number || ''); } },
                { label: 'المورد', render: function (r) { return self.escapeHtml(r.supplier_name || ''); } },
                { label: 'التاريخ', render: function (r) { return self.dateOnly(r.order_date); } },
                { label: 'الحالة', render: function (r) { return self.pharmacyStatus(r.status); } },
                { label: 'إجراءات', render: function (r) {
                    if (r.status === 'cancelled' || r.status === 'received') return '-';
                    return '<button class="btn btn-sm btn-outline" onclick="LeviPOS.openReceiveOrderModal(' + r.id + ')">استلام</button>';
                } }
            ], orders ? (orders.orders || []) : [], 'لا توجد طلبيات توريد.');

            const forecast = results[1];
            this.renderTable('pharmacy-forecast-table', [
                { label: 'الدواء', render: function (r) { return self.escapeHtml(r.name || ''); } },
                { label: 'الرصيد', render: function (r) { return self.num(r.stock_qty); } },
                { label: 'متوسط البيع اليومي', render: function (r) { return self.num(r.avg_daily_sales); } },
                { label: 'أيام التغطية', render: function (r) { return self.num(r.days_of_cover); } },
                { label: 'الكمية المقترحة', render: function (r) { return self.num(r.suggested_order_qty); } },
                { label: 'الحالة', render: function (r) { return self.pharmacyStatus(r.status); } }
            ], forecast ? (forecast.items || []) : [], 'لا توجد تنبؤات كافية.');

            const alerts = results[2];
            const alertsEl = document.getElementById('pharmacy-supplier-alerts');
            if (alertsEl) {
                alertsEl.innerHTML = this.statCard('إجمالي المستحق', this.num(alerts ? alerts.total_due : 0), 'blue')
                    + this.statCard('فواتير متأخرة', this.num(alerts ? alerts.overdue_count : 0), 'red')
                    + this.statCard('عدد الفواتير', this.num(alerts ? alerts.invoice_count : 0), 'gray');
            }
        },

        async openSupplyOrderModal() {
            const products = await this.ensureProductCatalog();
            const suppliersData = await this.apiGet('suppliers.list', { limit: 300 });
            const suppliers = suppliersData ? (suppliersData.suppliers || []) : [];
            const body = '<div class="form-group"><label for="sof-supplier">المورد *</label>'
                + '<select id="sof-supplier" class="form-select">' + this.options(suppliers, 'id', 'name', '', 'اختر المورد...') + '</select></div>'
                + '<div class="form-group"><label for="sof-product">الدواء *</label>'
                + '<select id="sof-product" class="form-select">' + this.productOptionHtml(products, '') + '</select></div>'
                + '<div class="form-group"><label for="sof-quantity">الكمية *</label>'
                + '<input type="number" id="sof-quantity" class="form-control" min="1" step="1" value="1"></div>'
                + '<div class="form-group"><label for="sof-cost">سعر التكلفة *</label>'
                + '<input type="number" id="sof-cost" class="form-control" min="0" step="0.01" value="0"></div>'
                + '<div class="form-group"><label for="sof-notes">ملاحظات</label>'
                + '<textarea id="sof-notes" class="form-control" rows="2"></textarea></div>';
            const footer = '<button type="button" class="btn btn-primary" onclick="LeviPOS.submitSupplyOrder()">إنشاء الطلب</button>';
            this.openModal('طلب توريد جديد', body, footer);
        },

        async submitSupplyOrder() {
            const supplierId = parseInt((document.getElementById('sof-supplier') || {}).value || '0', 10) || 0;
            const productId = this.pharmacyProductId('sof-product');
            const quantity = parseInt((document.getElementById('sof-quantity') || {}).value || '0', 10) || 0;
            const unitCost = parseFloat((document.getElementById('sof-cost') || {}).value || '0') || 0;
            const notes = document.getElementById('sof-notes');
            if (!supplierId || !productId || quantity <= 0) {
                this.toast('أكمل بيانات المورد والدواء والكمية.', 'error');
                return;
            }
            const result = await this.apiPost('purchase_orders.create', {
                supplier_id: supplierId,
                order_date: new Date().toISOString().slice(0, 19).replace('T', ' '),
                notes: notes ? notes.value : '',
                items: [{ product_id: productId, quantity: quantity, unit_cost: unitCost }]
            });
            if (!result) return;
            this.toast('تم إنشاء طلب التوريد.', 'success');
            this.closeModal();
            this.loadPharmacySupply();
        },

        async openReceiveOrderModal(orderId) {
            const data = await this.apiGet('purchase_orders.get', { order_id: orderId });
            if (!data) return;
            const self = this;
            const items = data.items || [];
            let rows = '';
            items.forEach(function (item) {
                const remaining = Math.max(0, Number(item.quantity || 0) - Number(item.received_quantity || 0));
                rows += '<tr><td>' + self.escapeHtml(item.product_name || item.product_id)
                    + '</td><td>' + self.num(item.quantity) + '</td><td>' + self.num(item.received_quantity)
                    + '</td><td><input type="number" min="0" max="' + remaining + '" step="1" class="form-control" id="rof-item-' + item.id + '" value="' + remaining + '"></td></tr>';
            });
            const body = '<div class="table-responsive"><table class="table table-sm"><thead><tr>'
                + '<th>الدواء</th><th>المطلوب</th><th>المستلم سابقًا</th><th>الاستلام الآن</th>'
                + '</tr></thead><tbody>' + rows + '</tbody></table></div>';
            const footer = '<button type="button" class="btn btn-primary" onclick="LeviPOS.submitReceiveOrder(' + orderId + ')">تسجيل الاستلام</button>';
            this.openModal('استلام طلب التوريد ' + (data.order_number || orderId), body, footer, 'wide');
        },

        async submitReceiveOrder(orderId) {
            const inputs = document.querySelectorAll('#modal-box input[id^="rof-item-"]');
            const items = [];
            inputs.forEach(function (input) {
                const itemId = parseInt(String(input.id).replace('rof-item-', ''), 10);
                const qty = parseInt(input.value || '0', 10) || 0;
                if (itemId > 0 && qty > 0) items.push({ purchase_order_item_id: itemId, received_quantity: qty });
            });
            if (!items.length) { this.toast('أدخل كمية مستلمة صحيحة.', 'error'); return; }
            const result = await this.apiPost('purchase_orders.receive', { order_id: orderId, items: items });
            if (!result) return;
            this.toast('تم تسجيل الاستلام.', 'success');
            this.closeModal();
            this.loadPharmacySupply();
        }
    };

    window.LeviPOS = LeviPOS;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { LeviPOS.init(); });
    } else {
        LeviPOS.init();
    }
})();
