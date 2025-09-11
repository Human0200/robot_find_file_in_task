// app.js — Только для робота "Запись результата задачи"

document.addEventListener('DOMContentLoaded', function () {
    // Проверяем, загружен ли BX24
    if (typeof BX24 === 'undefined') {
        console.error('BX24 не загружен. Приложение должно работать внутри Bitrix24.');
        alert('Ошибка: приложение запущено не в Bitrix24');
        return;
    }

    const robot = {
        code: 'robot_task_result_to_entity',
        name: 'Записать результат задачи в сущность (LeadSpace)',
        handler: 'https://app.lead-space.ru/robot_find_file/TaskResultToEntity.php',
        statusElementId: 'status-task'
    };

    const statusEl = document.getElementById(robot.statusElementId);
    const createBtn = document.getElementById('createTaskResultButton');
    const deleteBtn = document.getElementById('deleteTaskResultButton');
    const listBtn = document.getElementById('ListRobots');

    // === Проверка доступности REST API ===
    async function checkApiAvailability() {
        try {
            const result = await callMethod('methods', {});
            const methods = result.data();
            const hasBizProc = Object.keys(methods).some(method => method.startsWith('bizproc'));
            
            if (!hasBizProc) {
                throw new Error('Модуль бизнес-процессов недоступен на вашем портале');
            }
            return true;
        } catch (error) {
            console.error('API недоступно:', error);
            showNotify('❌ Ошибка доступа к REST API: ' + error.message, 'error');
            return false;
        }
    }

    // === Проверка прав доступа ===
    async function checkPermissions() {
        try {
            const result = await callMethod('profile', {});
            const user = result.data();
            
            if (!user.ADMIN) {
                throw new Error('Недостаточно прав. Требуются права администратора');
            }
            return true;
        } catch (error) {
            console.error('Ошибка прав доступа:', error);
            showNotify('❌ Ошибка прав доступа: ' + error.message, 'error');
            return false;
        }
    }

    // === Проверка статуса робота ===
    async function checkStatus() {
        try {
            // Сначала проверяем доступность API
            const apiAvailable = await checkApiAvailability();
            if (!apiAvailable) return;

            // Проверяем права
            const hasPermissions = await checkPermissions();
            if (!hasPermissions) return;

            const result = await callMethod('bizproc.robot.list', {});
            const installed = result.data().includes(robot.code);
            updateStatus(installed);
        } catch (err) {
            console.error('Ошибка проверки статуса:', err);
            if (statusEl) {
                statusEl.textContent = 'Ошибка';
                statusEl.className = 'robot-status error';
            }
            showNotify('❌ Ошибка проверки статуса: ' + err.message, 'error');
        }
    }

    // === Установить робота ===
    createBtn?.addEventListener('click', async () => {
        try {
            const apiAvailable = await checkApiAvailability();
            if (!apiAvailable) return;

            const hasPermissions = await checkPermissions();
            if (!hasPermissions) return;

            const params = {
                CODE: robot.code,
                HANDLER: robot.handler.trim(),
                AUTH_USER_ID: 1,
                NAME: robot.name,
                PROPERTIES: {
                    task_id: {
                        Name: 'ID задачи',
                        Type: 'int',
                        Required: 'Y'
                    },
                    entity_type: {
                        Name: 'Тип сущности',
                        Type: 'select',
                        Required: 'Y',
                        Options: {
                            lead: 'Лид',
                            contact: 'Контакт',
                            company: 'Компания',
                            deal: 'Сделка',
                            smart_process: 'Смарт-процесс'
                        }
                    },
                    entity_id: {
                        Name: 'ID сущности',
                        Type: 'int',
                        Required: 'Y'
                    },
                    field_code: {
                        Name: 'Код поля для файлов',
                        Type: 'string',
                        Required: 'Y'
                    },
                    smart_process_id: {
                        Name: 'ID смарт-процесса',
                        Type: 'int',
                        Required: 'N'
                    }
                },
                RETURN_PROPERTIES: {
                    success: { Name: 'Успешно', Type: 'bool' },
                    files_count: { Name: 'Кол-во файлов', Type: 'int' },
                    files_ids: { Name: 'ID файлов', Type: 'string' },
                    text_result: { Name: 'Текст', Type: 'string' },
                    message: { Name: 'Сообщение', Type: 'string' }
                }
            };

            await callMethod('bizproc.robot.add', params);
            showNotify('✅ Робот установлен');
            updateStatus(true);
        } catch (err) {
            showNotify('❌ Ошибка установки: ' + (err.message || err), 'error');
        }
    });

    // === Удалить робота ===
    deleteBtn?.addEventListener('click', async () => {
        if (!confirm('Удалить робота?')) return;

        try {
            const apiAvailable = await checkApiAvailability();
            if (!apiAvailable) return;

            const hasPermissions = await checkPermissions();
            if (!hasPermissions) return;

            await callMethod('bizproc.robot.delete', { CODE: robot.code });
            showNotify('✅ Робот удалён');
            updateStatus(false);
        } catch (err) {
            showNotify('❌ Ошибка удаления: ' + (err.message || err), 'error');
        }
    });

    // === Показать список роботов ===
    listBtn?.addEventListener('click', async () => {
        try {
            const apiAvailable = await checkApiAvailability();
            if (!apiAvailable) return;

            const hasPermissions = await checkPermissions();
            if (!hasPermissions) return;

            const result = await callMethod('bizproc.robot.list', {});
            const list = result.data();
            showNotify(list.length ? 'Роботы:\n' + list.join('\n') : 'Нет роботов');
        } catch (err) {
            showNotify('❌ Ошибка: ' + (err.message || err), 'error');
        }
    });

    // === Вспомогательные функции ===

    function callMethod(method, params) {
        return new Promise((resolve, reject) => {
            BX24.callMethod(method, params, (result) => {
                if (result.error()) {
                    const error = result.error();
                    console.error('REST Error:', error);
                    
                    // Обработка специфических ошибок
                    if (error.includes('404') || error.includes('not found')) {
                        reject(new Error('Метод не найден. Возможно, модуль бизнес-процессов не установлен'));
                    } else if (error.includes('access denied') || error.includes('permission')) {
                        reject(new Error('Доступ запрещен. Проверьте права доступа'));
                    } else {
                        reject(error);
                    }
                } else {
                    resolve(result);
                }
            });
        });
    }

    function updateStatus(installed) {
        if (statusEl) {
            statusEl.textContent = installed ? 'Установлен' : 'Не установлен';
            statusEl.className = 'robot-status ' + (installed ? 'installed' : 'not-installed');
        }
    }

    function showNotify(message, type = 'success') {
        const notif = document.createElement('div');
        notif.style.cssText = `
            position: fixed; top: 20px; right: 20px; padding: 12px 16px;
            background: ${type === 'success' ? '#4CAF50' : '#f44336'};
            color: white; border-radius: 6px; z-index: 10000;
            max-width: 300px; word-break: break-word; font-size: 14px;
        `;
        notif.textContent = message;
        document.body.appendChild(notif);
        setTimeout(() => notif.remove(), 5000);
    }

    // === Плавающая иконка ===
    const imgContainer = document.getElementById('image-container');
    if (imgContainer) {
        const updatePos = () => {
            const h = window.innerHeight, w = window.innerWidth;
            const st = window.pageYOffset, sl = window.pageXOffset;
            const eh = imgContainer.offsetHeight, ew = imgContainer.offsetWidth;
            imgContainer.style.bottom = Math.max(0, h - (st + eh)) + 'px';
            imgContainer.style.right = Math.max(0, w - (sl + ew)) + 'px';
        };
        window.addEventListener('scroll', updatePos);
        window.addEventListener('resize', updatePos);
        updatePos();
    }

    // === Инициализация ===
    checkStatus();
});