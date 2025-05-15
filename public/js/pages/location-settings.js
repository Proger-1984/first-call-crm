// Страница настройки локаций для автозвонка
document.addEventListener('DOMContentLoaded', function() {
    // Переменные для работы с Яндекс Картами
    let map, polygon, placemark;
    let polygonCoordinates = [];
    let editMode = false;
    let editLocationId = null;
    
    // Переменные для работы с API
    let userId = null;
    let activeSubscriptions = [];
    let userLocations = {};
    let currentSubscriptionId = null;
    
    // Получаем JWT-токен из localStorage
   // const jwtToken = localStorage.getItem('access_token');
    const jwtToken = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpYXQiOjE3NDczMTIzMjIsImV4cCI6MTc0NzMyOTEyMiwidXNlcl9pZCI6Miwicm9sZSI6ImFkbWluIiwiZGV2aWNlX3R5cGUiOiJtb2JpbGUifQ.Kh2fznuBlBNrMBGba_8Xgsz_WCj9UzfU1SRJQ-t1SyY';
    
    // Проверяем наличие токена
    if (!jwtToken) {
        console.error('Токен авторизации не найден');
        showError('Для доступа к этой странице необходимо авторизоваться');
        // Перенаправление на страницу авторизации через 2 секунды
        setTimeout(() => {
            window.location.href = '/telegram-auth.html';
        }, 2000);
        return;
    }
    
    // Получаем HTML-элементы и другие исходные переменные
    const subscriptionSelect = document.getElementById('subscription-select');
    const locationsList = document.getElementById('locations-list');
    const locationForm = document.getElementById('location-form');
    const locationNameInput = document.getElementById('location-name');
    const saveLocationBtn = document.getElementById('save-location');
    const submitLocationBtn = saveLocationBtn; // Создаем ссылку для совместимости
    const cancelEditBtn = document.getElementById('cancel-edit');
    const mapContainer = document.getElementById('map');
    const loadingIndicator = document.getElementById('loading-indicator');
    
    // Проверяем, что все необходимые элементы найдены
    if (!locationForm || !locationNameInput || !saveLocationBtn || !cancelEditBtn) {
        console.error('Не удалось найти один или несколько необходимых HTML-элементов', {
            locationForm: !!locationForm,
            locationNameInput: !!locationNameInput,
            saveLocationBtn: !!saveLocationBtn,
            cancelEditBtn: !!cancelEditBtn
        });
    }
    
    // Функция для получения заголовков с токеном авторизации
    function getAuthHeaders() {
        return {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': `Bearer ${jwtToken}`
        };
    }
    
    // Функция для отображения сообщения об ошибке
    function showError(message) {
        const errorDiv = document.getElementById('error-message');
        errorDiv.textContent = message;
        errorDiv.style.display = 'block';
        
        setTimeout(() => {
            errorDiv.style.display = 'none';
        }, 5000);
    }
    
    // Функция для отображения сообщения об успехе
    function showSuccess(message) {
        const successDiv = document.getElementById('success-message');
        successDiv.textContent = message;
        successDiv.style.display = 'block';
        
        setTimeout(() => {
            successDiv.style.display = 'none';
        }, 3000);
    }
    
    // Функция для отображения/скрытия индикатора загрузки
    function toggleLoading(show) {
        loadingIndicator.style.display = show ? 'block' : 'none';
    }
    
    // Получение активных подписок пользователя
    async function getActiveSubscriptions() {
        try {
            toggleLoading(true);
            
            console.log('Запрашиваем активные подписки пользователя...');
            
            // Добавляем заголовок Authorization
            const response = await fetch('/api/v1/subscriptions', {
                method: 'GET',
                headers: getAuthHeaders()
            });
            
            console.log('Запрос на получение подписок с заголовками:', getAuthHeaders());
            
            if (!response.ok) {
                throw new Error('Не удалось получить список подписок');
            }
            
            const data = await response.json();
            console.log('Полученные данные подписок:', JSON.stringify(data, null, 2));
            
            if (data.code === 200 && data.status === 'success') {
                // Новый формат ответа API - более простая структура
                activeSubscriptions = data.data.subscriptions;
                
                // Проверяем структуру данных и наличие необходимых полей
                if (activeSubscriptions && activeSubscriptions.length > 0) {
                    console.log('Структура первой подписки:', JSON.stringify(activeSubscriptions[0], null, 2));
                    
                    // Проверим наличие и структуру объекта location
                    const hasLocation = activeSubscriptions.every(sub => sub.location && typeof sub.location === 'object');
                    console.log('Все подписки имеют объект location:', hasLocation);
                    
                    // Проверим наличие bounds в location
                    const hasBounds = activeSubscriptions.filter(sub => 
                        sub.location && 
                        sub.location.bounds && 
                        typeof sub.location.bounds === 'object'
                    );
                    console.log(`${hasBounds.length} из ${activeSubscriptions.length} подписок имеют границы`);
                }
                
                // Обновляем выпадающий список подписок
                populateSubscriptionsSelect();
            } else {
                throw new Error(data.message || 'Ошибка при получении подписок');
            }
        } catch (error) {
            console.error('Ошибка при получении подписок:', error);
            showError(error.message);
        } finally {
            toggleLoading(false);
        }
    }
    
    // Заполнение выпадающего списка подписок
    function populateSubscriptionsSelect() {
        try {
            // Очищаем текущие опции
            subscriptionSelect.innerHTML = '<option value="">Выберите подписку</option>';
            
            console.log('Заполняем список подписок, количество: ', activeSubscriptions.length);
            
            // Проверяем, что у нас есть подписки для отображения
            if (!activeSubscriptions || !Array.isArray(activeSubscriptions) || activeSubscriptions.length === 0) {
                console.warn('Нет доступных подписок для отображения');
                return;
            }
            
            // Заполняем новыми опциями
            activeSubscriptions.forEach(subscription => {
                // Проверка на наличие необходимых полей
                if (!subscription || !subscription.id || !subscription.location) {
                    console.warn('Некорректная структура подписки:', subscription);
                    return;
                }
                
                try {
                    const option = document.createElement('option');
                    option.value = subscription.id;
                    
                    // В новом формате имя локации уже включает категорию
                    option.textContent = subscription.location.name;
                    
                    subscriptionSelect.appendChild(option);
                } catch (optionError) {
                    console.error('Ошибка при создании опции для подписки:', optionError, subscription);
                }
            });
            
            // Добавляем обработчик изменения выбранной подписки, если его еще нет
            if (!subscriptionSelect._hasChangeHandler) {
                subscriptionSelect.addEventListener('change', handleSubscriptionChange);
                subscriptionSelect._hasChangeHandler = true;
            }
            
            // Если есть сохраненный ID подписки в URL, устанавливаем его
            const urlParams = new URLSearchParams(window.location.search);
            const savedSubscriptionId = urlParams.get('subscription_id');
            
            if (savedSubscriptionId && activeSubscriptions.some(s => s.id == savedSubscriptionId)) {
                subscriptionSelect.value = savedSubscriptionId;
                // Запускаем обработчик изменения, чтобы обновить интерфейс
                handleSubscriptionChange();
            } else if (activeSubscriptions.length > 0) {
                // Если нет сохраненного ID, но есть подписки, выбираем первую
                subscriptionSelect.value = activeSubscriptions[0].id;
                // Запускаем обработчик изменения, чтобы обновить интерфейс
                handleSubscriptionChange();
            }
        } catch (error) {
            console.error('Ошибка при заполнении списка подписок:', error);
            showError('Произошла ошибка при отображении списка подписок. Пожалуйста, обновите страницу.');
        }
    }
    
    // Визуализация границ подписки
    function visualizeSubscriptionBounds(subscriptionId) {
        try {
            const subscription = activeSubscriptions.find(s => s.id == subscriptionId);
            
            // Выводим полную информацию о подписке для отладки
            console.log('Детальная информация о подписке:', JSON.stringify(subscription, null, 2));
            
            if (!subscription || !subscription.location) {
                console.warn('Невозможно отобразить границы - нет данных о локации подписки');
                showError('У выбранной подписки нет определенной локации. Пожалуйста, обратитесь к администратору системы.');
                return false;
            }
            
            console.log('Данные о локации:', JSON.stringify(subscription.location, null, 2));
            
            // Если у локации нет границ, пробуем найти центр и использовать его
            if (!subscription.location.bounds) {
                console.warn('У локации нет определенных границ, используем центр если доступен');
                
                if (subscription.location.center_lat && subscription.location.center_lng) {
                    // Если есть центр, используем его и устанавливаем стандартные границы
                    const centerLat = parseFloat(subscription.location.center_lat);
                    const centerLng = parseFloat(subscription.location.center_lng);
                    
                    // Проверяем валидность координат центра
                    if (isNaN(centerLat) || isNaN(centerLng) || 
                        centerLat < -90 || centerLat > 90 || 
                        centerLng < -180 || centerLng > 180) {
                        console.error('Некорректные координаты центра локации:', centerLat, centerLng);
                        showError('У локации некорректные координаты центра. Пожалуйста, обратитесь к администратору системы.');
                        return false;
                    }
                    
                    // Устанавливаем центр карты
                    map.setCenter([centerLat, centerLng], 10);
                    
                    // Создаем маркер центра
                    const centerMarker = new ymaps.Placemark([centerLat, centerLng], {
                        hintContent: 'Центр локации подписки',
                        balloonContent: `Локация: ${subscription.location.name}`
                    }, {
                        preset: 'islands#blueInfoIcon'
                    });
                    
                    map.geoObjects.add(centerMarker);
                    
                    showSuccess('Карта отцентрирована по центру локации. Границы локации не определены, вы можете создавать полигоны без географических ограничений.');
                    return true;
                } else {
                    // Если нет ни границ, ни центра, пытаемся получить координаты по названию
                    const locationName = subscription.location.name.split('|')[0].trim(); // Берем только имя локации без категории
                    ymaps.geocode(locationName, {
                        results: 1
                    }).then(function (res) {
                        if (res.geoObjects.getLength() > 0) {
                            const firstGeoObject = res.geoObjects.get(0);
                            const coords = firstGeoObject.geometry.getCoordinates();
                            const bounds = firstGeoObject.properties.get('boundedBy');
                            
                            // Центрируем карту
                            map.setCenter(coords, 10);
                            
                            if (bounds) {
                                map.setBounds(bounds, {
                                    checkZoomRange: true
                                });
                            }
                            
                            // Добавляем маркер
                            const locationMarker = new ymaps.Placemark(coords, {
                                hintContent: `Локация: ${locationName}`,
                                balloonContent: 'Примерный центр локации (определен по названию)'
                            }, {
                                preset: 'islands#greenInfoIcon'
                            });
                            
                            map.geoObjects.add(locationMarker);
                            
                            showSuccess('Карта отцентрирована по названию локации. Границы локации не определены, вы можете создавать полигоны без географических ограничений.');
                        } else {
                            showError('Не удалось найти локацию по названию. Вы можете создавать полигоны без географических ограничений.');
                        }
                    }).catch(function(error) {
                        console.error('Ошибка геокодирования:', error);
                        showError('Не удалось определить координаты локации. Вы можете создавать полигоны без географических ограничений.');
                    });
                    
                    return true;
                }
            }
            
            // Получаем границы и преобразуем их в числа для правильного сравнения
            const bounds = subscription.location.bounds;
            
            // Преобразуем границы в числовые значения, если они строки
            if (bounds) {
                bounds.north = parseFloat(bounds.north);
                bounds.south = parseFloat(bounds.south);
                bounds.east = parseFloat(bounds.east);
                bounds.west = parseFloat(bounds.west);
                
                console.log('Границы (преобразованные в числа):', bounds);
            }
            
            // Проверяем, что границы валидны (имеют смысл географически)
            if (!bounds || bounds.north <= bounds.south || bounds.east <= bounds.west || 
                bounds.north > 90 || bounds.south < -90 || 
                bounds.east > 180 || bounds.west < -180) {
                console.error('Невалидные границы локации:', bounds);
                showError('У выбранной подписки некорректные географические границы. Пожалуйста, обратитесь к администратору системы.');
                return false;
            }
            
            // Проверяем, полностью ли инициализировано API карт
            if (typeof ymaps.Rectangle !== 'function') {
                console.warn('ymaps.Rectangle не доступен, отложенная отрисовка границ');
                // Отложим отрисовку до полной загрузки API
                ymaps.ready(function() {
                    createBoundaryRectangle(bounds);
                });
            } else {
                // Если API доступен, рисуем прямоугольник сразу
                createBoundaryRectangle(bounds);
            }
            
            // Центрируем карту на границах
            map.setBounds([
                [bounds.south, bounds.west],
                [bounds.north, bounds.east]
            ], {
                checkZoomRange: true
            });
            
            return true;
        } catch (error) {
            console.error('Ошибка при визуализации границ подписки:', error);
            showError('Произошла ошибка при отображении границ локации. Пожалуйста, обновите страницу.');
            return false;
        }
    }
    
    // Вспомогательная функция для создания прямоугольника границ
    function createBoundaryRectangle(bounds) {
        if (!bounds || !map) return;
        
        try {
            // Преобразуем границы в числа для корректного отображения
            const south = parseFloat(bounds.south);
            const west = parseFloat(bounds.west);
            const north = parseFloat(bounds.north);
            const east = parseFloat(bounds.east);
            
            // Проверка валидности преобразованных координат
            if (isNaN(south) || isNaN(west) || isNaN(north) || isNaN(east)) {
                console.error('Невалидные числовые координаты для прямоугольника границ:', { south, west, north, east });
                return;
            }
            
            console.log('Создание прямоугольника границ с координатами:', { south, west, north, east });
            
            // Создаем прямоугольник, обозначающий границы локации
            const boundaryRectangle = new ymaps.Rectangle([
                [south, west],
                [north, east]
            ], {
                hintContent: 'Границы локации подписки', // Только подсказка при наведении
                balloonContentHeader: null, // Отключаем всплывающую подсказку при клике
                interactivityModel: 'default#transparent' // Отключаем интерактивность прямоугольника
            }, {
                fillColor: '#00000000',  // Прозрачная заливка
                strokeColor: '#ff6600',  // Оранжевая граница
                strokeWidth: 2,
                strokeStyle: 'dash',     // Пунктирная линия
                zIndex: 900,            // Поверх других объектов, но под точками ошибок
                interactivityModel: 'default#transparent' // Отключаем интерактивность прямоугольника
            });
            
            map.geoObjects.add(boundaryRectangle);
            
            // Добавляем информационную панель с границами в верхнем углу карты
            const boundsInfo = document.createElement('div');
            boundsInfo.className = 'map-bounds-info';
            boundsInfo.innerHTML = `
                <div class="card p-2 mb-2">
                    <small class="mb-1"><strong>Границы локации:</strong></small>
                    <small>С: ${north.toFixed(4)}, Ю: ${south.toFixed(4)}</small>
                    <small>В: ${east.toFixed(4)}, З: ${west.toFixed(4)}</small>
                </div>
            `;
            
            // Добавляем стили для информационной панели
            if (!document.getElementById('bounds-info-styles')) {
                const style = document.createElement('style');
                style.id = 'bounds-info-styles';
                style.innerHTML = `
                    .map-bounds-info {
                        position: absolute;
                        top: 10px;
                        right: 10px;
                        z-index: 1000;
                        max-width: 200px;
                        font-size: 12px;
                    }
                `;
                document.head.appendChild(style);
            }
            
            // Находим контейнер карты и добавляем информационную панель
            const mapContainer = document.getElementById('map');
            if (mapContainer) {
                mapContainer.appendChild(boundsInfo);
            }
        } catch (e) {
            console.error('Ошибка при создании прямоугольника границ:', e);
            showError('Не удалось отобразить границы локации. Пожалуйста, обновите страницу или попробуйте позднее.');
        }
    }
    
    // Обработка изменения выбранной подписки
    async function handleSubscriptionChange() {
        console.log('Вызвана функция handleSubscriptionChange');
        
        try {
            const subscriptionId = subscriptionSelect.value;
            currentSubscriptionId = subscriptionId;
            
            console.log('Выбрана подписка:', subscriptionId);
            
            // Сбрасываем форму и локации при изменении подписки
            resetLocationForm();
            
            // Если нет выбранной подписки, скрываем карту и форму
            if (!subscriptionId) {
                console.log('Не выбрана подписка, скрываем карту и форму');
                
                // Очищаем карту, если она инициализирована
                if (map) {
                    map.geoObjects.removeAll();
                }
                
                if (mapContainer) mapContainer.style.display = 'none';
                if (locationForm) locationForm.style.display = 'none';
                if (locationsList) locationsList.innerHTML = '';
                return;
            }
            
            // Проверяем, существует ли такая подписка в активных
            const subscription = activeSubscriptions.find(s => s.id == subscriptionId);
            if (!subscription) {
                console.error('Выбранная подписка не найдена среди активных:', subscriptionId);
                showError('Выбранная подписка не найдена. Пожалуйста, обновите страницу.');
                return;
            }
            
            // Показываем индикатор загрузки
            toggleLoading(true);
            
            // Получаем локации для выбранной подписки
            console.log('Загружаем локации для подписки', subscriptionId);
            await getUserLocationsBySubscription(subscriptionId);
            
            console.log('Локации загружены, переходим к работе с картой');
            
            // Инициализируем карту, если она еще не создана
            if (!map) {
                console.log('Карта не инициализирована, создаем новую');
                // Если карты еще нет, создаем ее (будет выполнено один раз)
                await initYandexMap(subscriptionId);
            } else {
                console.log('Карта уже инициализирована, обновляем для новой подписки');
                // Если карта уже есть, обновляем ее для новой подписки
                updateMapForSubscription(subscriptionId);
                
                // Визуализируем границы подписки
                visualizeSubscriptionBounds(subscriptionId);
            }
            
            // Показываем карту и форму
            if (mapContainer) {
                console.log('Показываем карту');
                mapContainer.style.display = 'block';
            }
            
            if (locationForm) {
                console.log('Показываем форму локации');
                locationForm.style.display = 'block';
            }
            
            console.log('Обработка изменения подписки завершена успешно');
        } catch (error) {
            console.error('Ошибка при обработке изменения подписки:', error);
            showError('Произошла ошибка при загрузке данных подписки. Пожалуйста, попробуйте еще раз.');
        } finally {
            // Скрываем индикатор загрузки
            toggleLoading(false);
        }
    }
    
    // Получение локаций пользователя по ID подписки
    async function getUserLocationsBySubscription(subscriptionId) {
        console.log('Запрос локаций для подписки:', subscriptionId);
        
        if (!subscriptionId || isNaN(parseInt(subscriptionId))) {
            console.error('Некорректный ID подписки:', subscriptionId);
            throw new Error('Некорректный ID подписки');
        }
        
        try {
            toggleLoading(true);
            
            // Формируем URL с ID подписки как часть пути, и добавляем query-параметр
            const url = `/api/v1/location-polygons/subscription/${subscriptionId}?subscription_id=${subscriptionId}`;
            
            console.log('Отправка запроса на получение локаций:', url);
            
            // Добавляем случайный параметр для избежания кэширования
            const cacheBustUrl = `${url}&_=${new Date().getTime()}`;
            
            const response = await fetch(cacheBustUrl, {
                method: 'GET',
                headers: getAuthHeaders(),
                cache: 'no-store' // Запрещаем кэширование
            });
            
            if (!response.ok) {
                // Пытаемся прочитать ошибку
                try {
                    const errorData = await response.json();
                    console.error('Ошибка при получении локаций:', errorData);
                    throw new Error(errorData?.message || `Не удалось получить список локаций. Код: ${response.status}`);
                } catch (parseError) {
                    console.error('Ошибка парсинга ответа:', parseError);
                    throw new Error(`Не удалось получить список локаций. Код: ${response.status}`);
                }
            }
            
            const data = await response.json();
            console.log('Получены данные локаций:', data);
            
            if (data.code === 200 && data.status === 'success') {
                if (!data.data || !Array.isArray(data.data.location_polygons)) {
                    console.warn('Получен некорректный формат данных локаций:', data);
                    userLocations[subscriptionId] = [];
                } else {
                    console.log(`Получено ${data.data.location_polygons.length} локаций для подписки ${subscriptionId}`);
                    userLocations[subscriptionId] = data.data.location_polygons;
                }
                
                // Отображаем список локаций
                renderLocationsList(subscriptionId);
                
                return userLocations[subscriptionId];
            } else {
                console.error('Ошибка в ответе API:', data);
                throw new Error(data.message || 'Ошибка при получении локаций');
            }
        } catch (error) {
            console.error('Ошибка получения локаций для подписки', subscriptionId, ':', error);
            showError(error.message);
            throw error; // Пробрасываем ошибку дальше
        } finally {
            toggleLoading(false);
        }
    }
    
    // Отображение списка локаций
    function renderLocationsList(subscriptionId) {
        // Очищаем текущий список
        locationsList.innerHTML = '';
        
        const locations = userLocations[subscriptionId] || [];
        
        if (locations.length === 0) {
            // Создаем сообщение о пустом списке с иконкой
            const emptyMessage = document.createElement('div');
            emptyMessage.className = 'empty-message';
            emptyMessage.innerHTML = `
                <i class="bi bi-geo-alt" style="font-size: 2rem; display: block; margin-bottom: 1rem;"></i>
                <p class="mb-0">У вас пока нет сохраненных локаций для этой подписки</p>
            `;
            locationsList.appendChild(emptyMessage);
            return;
        }
        
        // Создаем элементы для каждой локации
        locations.forEach(location => {
            const locationItem = document.createElement('div');
            locationItem.className = 'location-item card';
            locationItem.setAttribute('data-location-id', location.id);
            
            // Добавляем обработчик клика на всю карточку локации для переключения на неё
            locationItem.addEventListener('click', function() {
                focusOnLocation(location);
            });
            
            const cardBody = document.createElement('div');
            cardBody.className = 'card-body';
            
            const cardHeader = document.createElement('div');
            cardHeader.className = 'd-flex justify-content-between align-items-center mb-2';
            
            const locationName = document.createElement('h6');
            locationName.className = 'card-title mb-0 fw-bold';
            locationName.textContent = location.name;
            
            const creationDate = document.createElement('p');
            creationDate.className = 'card-text text-muted small mb-2';
            creationDate.innerHTML = `<i class="bi bi-clock me-1"></i> ${formatDate(location.created_at)}`;
            
            const buttonsContainer = document.createElement('div');
            buttonsContainer.className = 'location-actions';
            
            const editButton = document.createElement('button');
            editButton.className = 'btn btn-sm btn-outline-primary';
            editButton.innerHTML = '<i class="bi bi-pencil"></i> Изменить';
            editButton.onclick = (e) => {
                e.stopPropagation(); // Предотвращаем всплытие события, чтобы не сработал клик по всей карточке
                
                // Создаем URL с параметрами для редактирования
                const currentUrl = new URL(window.location.href);
                currentUrl.searchParams.set('location_id', location.id);
                currentUrl.searchParams.set('subscription_id', subscriptionId);
                
                // Обновляем URL без перезагрузки страницы
                window.history.pushState({}, '', currentUrl);
                
                // Вызываем функцию редактирования
                editLocation(location);
            };
            
            const deleteButton = document.createElement('button');
            deleteButton.className = 'btn btn-sm btn-outline-danger';
            deleteButton.innerHTML = '<i class="bi bi-trash"></i> Удалить';
            deleteButton.onclick = (e) => {
                e.stopPropagation(); // Предотвращаем всплытие события, чтобы не сработал клик по всей карточке
                confirmDeleteLocation(location.id);
            };
            
            buttonsContainer.appendChild(editButton);
            buttonsContainer.appendChild(deleteButton);
            
            cardHeader.appendChild(locationName);
            
            cardBody.appendChild(cardHeader);
            cardBody.appendChild(creationDate);
            cardBody.appendChild(buttonsContainer);
            
            locationItem.appendChild(cardBody);
            locationsList.appendChild(locationItem);
        });
    }
    
    // Форматирование даты
    function formatDate(dateString) {
        if (!dateString) return "";
        
        const date = new Date(dateString);
        if (isNaN(date.getTime())) return dateString;
        
        return date.toLocaleDateString('ru-RU', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }
    
    // Инициализация Яндекс Карты
    async function initYandexMap(subscriptionId) {
        try {
            // Загружаем API Яндекс Карт, если еще не загружена
            if (!window.ymaps) {
                await loadYandexMapsAPI();
            }
            
            // Получаем данные о локации подписки для центрирования карты
            const subscription = activeSubscriptions.find(s => s.id == subscriptionId);
            if (!subscription) {
                throw new Error('Подписка не найдена');
            }
            
            // Проверяем наличие данных о локации
            if (!subscription.location) {
                throw new Error('У подписки отсутствуют данные о локации');
            }
            
            console.log('Инициализация карты для подписки:', subscription.id, 
                        'локация:', subscription.location.name);
            
            // Ждем полной инициализации API перед созданием карты
            return new Promise((resolve) => {
                ymaps.ready(() => {
                    console.log('Яндекс Карты загружены полностью');
                    
                    // Проверяем доступность всех необходимых конструкторов
                    const constructorsAvailable = 
                        typeof ymaps.Map === 'function' && 
                        typeof ymaps.GeoObject === 'function' && 
                        typeof ymaps.Polygon === 'function' && 
                        typeof ymaps.Placemark === 'function' && 
                        typeof ymaps.Rectangle === 'function';
                        
                    if (!constructorsAvailable) {
                        console.warn('Не все конструкторы Яндекс Карт доступны:', {
                            Map: typeof ymaps.Map === 'function',
                            GeoObject: typeof ymaps.GeoObject === 'function', 
                            Polygon: typeof ymaps.Polygon === 'function',
                            Placemark: typeof ymaps.Placemark === 'function',
                            Rectangle: typeof ymaps.Rectangle === 'function'
                        });
                    }
                    
                    // Создаем карту с центром в (0,0) - позже установим правильный центр
                    map = new ymaps.Map('map', {
                        center: [0, 0],
                        zoom: 10,
                        controls: ['zoomControl', 'rulerControl', 'typeSelector']
                    });
                    
                    // Если у локации есть сохраненные координаты, используем их
                    if (subscription.location.center_lat && subscription.location.center_lng) {
                        const lat = parseFloat(subscription.location.center_lat);
                        const lng = parseFloat(subscription.location.center_lng);
                        
                        if (!isNaN(lat) && !isNaN(lng)) {
                            const coords = [lat, lng];
                            
                            // Устанавливаем центр карты
                            map.setCenter(coords);
                            
                            // Если есть границы, устанавливаем их
                            if (subscription.location.bounds) {
                                const bounds = subscription.location.bounds;
                                try {
                                    map.setBounds([
                                        [bounds.south, bounds.west],
                                        [bounds.north, bounds.east]
                                    ], {
                                        checkZoomRange: true
                                    });
                                } catch (e) {
                                    console.error('Ошибка при установке границ:', e);
                                }
                            }
                        }
                    } else {
                        // Если нет сохраненных координат, используем геокодирование
                        // В новом формате имя может содержать категорию, отделяем её
                        const locationName = subscription.location.name.split('|')[0].trim();
                        
                        console.log('Геокодирование местоположения по названию:', locationName);
                        
                        ymaps.geocode(locationName, {
                            results: 1
                        }).then(function (res) {
                            if (res.geoObjects.getLength() > 0) {
                                // Получаем координаты первого геообъекта
                                const firstGeoObject = res.geoObjects.get(0);
                                const coords = firstGeoObject.geometry.getCoordinates();
                                
                                // Получаем границы геообъекта
                                const bounds = firstGeoObject.properties.get('boundedBy');
                                
                                console.log('Найдены координаты локации по названию:', coords, 'границы:', bounds);
                                
                                // Обновляем центр и границы карты
                                map.setCenter(coords);
                                if (bounds) {
                                    map.setBounds(bounds, {
                                        checkZoomRange: true
                                    });
                                }
                            } else {
                                console.warn('Не удалось найти геообъект по названию:', locationName);
                            }
                        }).catch(error => {
                            console.error('Ошибка геокодирования:', error);
                        });
                    }
                    
                    // Добавляем обработчики событий для работы с картой
                    map.events.add('click', function (e) {
                        if (!editMode) {
                            // Получаем координаты клика
                            const coords = e.get('coords');
                            
                            // Проверяем, находится ли точка в границах локации подписки
                            if (subscription.location && subscription.location.bounds) {
                                const bounds = subscription.location.bounds;
                                
                                // Преобразуем границы в числа для корректного сравнения
                                const numericBounds = {
                                    north: parseFloat(bounds.north),
                                    south: parseFloat(bounds.south),
                                    east: parseFloat(bounds.east),
                                    west: parseFloat(bounds.west)
                                };
                                
                                // Проверяем валидность границ
                                const boundsValid = numericBounds && 
                                      !isNaN(numericBounds.north) && !isNaN(numericBounds.south) &&
                                      !isNaN(numericBounds.east) && !isNaN(numericBounds.west) &&
                                      numericBounds.north > numericBounds.south && 
                                      numericBounds.east > numericBounds.west && 
                                      numericBounds.north <= 90 && numericBounds.south >= -90 && 
                                      numericBounds.east <= 180 && numericBounds.west >= -180;
                                      
                                if (boundsValid) {
                                    // ВАЖНО: в Яндекс.Картах координаты возвращаются как [широта, долгота]
                                    const lat = parseFloat(coords[0]); // Широта (первый элемент)
                                    const lng = parseFloat(coords[1]); // Долгота (второй элемент)
                                    
                                    // Подробный отладочный вывод координат
                                    console.log('Детальная отладка координат:');
                                    console.log('Клик координаты: лат=', lat, 'лнг=', lng);
                                    console.log('Границы: север=', numericBounds.north, 'юг=', numericBounds.south, 
                                                'восток=', numericBounds.east, 'запад=', numericBounds.west);
                                    
                                    // КРИТИЧЕСКАЯ ОШИБКА: инвертированная логика проверки!
                                    // Корректные проверки: точка должна быть ВНУТРИ границ
                                    const northOk = lat <= numericBounds.north;  // Широта меньше или равна северной границе
                                    const southOk = lat >= numericBounds.south;  // Широта больше или равна южной границе
                                    const eastOk = lng <= numericBounds.east;    // Долгота меньше или равна восточной границе
                                    const westOk = lng >= numericBounds.west;    // Долгота больше или равна западной границе
                                    
                                    console.log('Проверки границ:', {
                                        northOk, southOk, eastOk, westOk,
                                        'lat <= north': lat + ' <= ' + numericBounds.north + ' = ' + northOk,
                                        'lat >= south': lat + ' >= ' + numericBounds.south + ' = ' + southOk,
                                        'lng <= east': lng + ' <= ' + numericBounds.east + ' = ' + eastOk,
                                        'lng >= west': lng + ' >= ' + numericBounds.west + ' = ' + westOk
                                    });
                                    
                                    // Проверка, находится ли точка в границах
                                    // Точка должна быть внутри всех четырех границ
                                    const pointInBounds = northOk && southOk && eastOk && westOk;
                                    
                                    console.log('Точка в границах:', pointInBounds);
                                    
                                    // Убираем временное отключение блокировки
                                    if (!pointInBounds) {
                                        const errorMsg = `Эта точка находится за пределами локации вашей подписки.
                                        Координаты клика: [${lat.toFixed(4)}, ${lng.toFixed(4)}]
                                        Границы: С ${numericBounds.north.toFixed(4)}, Ю ${numericBounds.south.toFixed(4)}, В ${numericBounds.east.toFixed(4)}, З ${numericBounds.west.toFixed(4)}
                                        Пожалуйста, выберите точку внутри границ локации.`;
                                        
                                        console.warn(errorMsg);
                                        
                                        // Показываем ошибку пользователю
                                        showError(errorMsg);
                                        
                                        // ВАЖНО: блокируем добавление точки за пределами границ
                                        return;
                                    }
                                }
                            }
                            
                            // Добавляем точку в полигон
                            polygonCoordinates.push(coords);
                            
                            // Если это первая точка, создаем полигон
                            if (polygonCoordinates.length === 1) {
                                polygon = new ymaps.Polygon([polygonCoordinates], {}, {
                                    editorDrawingCursor: "crosshair",
                                    fillColor: '#00FF0022',
                                    strokeColor: '#0000FF',
                                    strokeWidth: 2,
                                    editorMaxPoints: 100 // Максимальное количество точек, которое можно добавить
                                });
                                
                                map.geoObjects.add(polygon);
                            } else {
                                // Обновляем координаты полигона
                                polygon.geometry.setCoordinates([polygonCoordinates]);
                                
                                // Если у нас достаточно точек для полигона, включаем редактор
                                if (polygonCoordinates.length >= 3) {
                                    // Включаем режим редактирования полигона после создания
                                    if (polygon.editor && !polygon.editor.state.get('editing')) {
                                        polygon.editor.startEditing();
                                        
                                        // Добавляем обработчик изменения полигона
                                        polygon.editor.events.add('vertexdragend', function() {
                                            polygonCoordinates = polygon.geometry.getCoordinates()[0];
                                        });
                                    }
                                }
                            }
                        }
                    });
                    
                    // Отображаем существующие локации на карте
                    showLocationsOnMap(subscriptionId);
                    
                    // Визуализируем границы подписки
                    visualizeSubscriptionBounds(subscriptionId);
                    
                    // Завершаем инициализацию
                    resolve();
                });
            });
        } catch (error) {
            showError('Не удалось инициализировать карту: ' + error.message);
            console.error('Ошибка инициализации карты:', error);
            return Promise.reject(error);
        }
    }
    
    // Загрузка API Яндекс Карт
    function loadYandexMapsAPI() {
        return new Promise((resolve, reject) => {
            // Проверяем, загружен ли уже API
            if (window.ymaps) {
                resolve();
                return;
            }
            
            // Создаем скрипт для загрузки Яндекс Карт
            const script = document.createElement('script');
            // Используем API ключ
            script.src = 'https://api-maps.yandex.ru/2.1/?apikey=88252981-692d-470d-af7d-10d2376eca97&lang=ru_RU';
            script.onload = resolve;
            script.onerror = () => reject(new Error('Не удалось загрузить API Яндекс Карт'));
            document.head.appendChild(script);
        });
    }
    
    // Обновление карты для новой подписки
    function updateMapForSubscription(subscriptionId) {
        // Очищаем текущие объекты на карте
        map.geoObjects.removeAll();
        
        // Удаляем информационную панель с границами, если она существует
        const existingBoundsInfo = document.querySelector('.map-bounds-info');
        if (existingBoundsInfo) {
            existingBoundsInfo.remove();
        }
        
        // Сбрасываем координаты полигона
        polygonCoordinates = [];
        polygon = null;
        
        // Получаем данные о локации подписки для центрирования карты
        const subscription = activeSubscriptions.find(s => s.id == subscriptionId);
        if (!subscription) {
            showError('Подписка не найдена');
            return;
        }
        
        // Получаем все локации для этой подписки
        const locations = userLocations[subscriptionId] || [];
        
        // Если у локации подписки есть сохраненные координаты, используем их
        if (subscription.location.center_lat && subscription.location.center_lng) {
            const coords = [subscription.location.center_lat, subscription.location.center_lng];
            
            // Устанавливаем центр карты
            map.setCenter(coords);
            
            // Если есть границы, устанавливаем их и показываем на карте
            if (subscription.location.bounds) {
                const bounds = subscription.location.bounds;
                map.setBounds([
                    [bounds.south, bounds.west],
                    [bounds.north, bounds.east]
                ], {
                    checkZoomRange: true
                });
                
                // Используем общую функцию для создания прямоугольника границ
                createBoundaryRectangle(bounds);
            }
        } else if (locations.length > 0 && locations.some(loc => loc.bounds)) {
            // Если у локации подписки нет координат, но есть локации пользователя с границами
            // Найдем все границы и объединим их
            const allBounds = locations
                .filter(loc => loc.bounds)
                .map(loc => loc.bounds);
                
            if (allBounds.length > 0) {
                // Вычисляем объединенные границы
                let north = -90, south = 90, east = -180, west = 180;
                
                allBounds.forEach(bound => {
                    north = Math.max(north, bound.north);
                    south = Math.min(south, bound.south);
                    east = Math.max(east, bound.east);
                    west = Math.min(west, bound.west);
                });
                
                // Устанавливаем границы карты
                map.setBounds([[south, west], [north, east]], {
                    checkZoomRange: true
                });
            } else {
                // Если нет сохраненных границ, используем геокодирование
                centerMapByLocationName(subscription.location.name);
            }
        } else {
            // Если нет сохраненных координат, используем геокодирование
            centerMapByLocationName(subscription.location.name);
        }
        
        // Отображаем существующие локации на карте
        showLocationsOnMap(subscriptionId);
    }
    
    // Центрирование карты по названию локации с помощью геокодирования
    function centerMapByLocationName(locationName) {
        ymaps.geocode(locationName, {
            results: 1
        }).then(function (res) {
            // Получаем координаты первого геообъекта
            const firstGeoObject = res.geoObjects.get(0);
            const coords = firstGeoObject.geometry.getCoordinates();
            
            // Получаем границы геообъекта
            const bounds = firstGeoObject.properties.get('boundedBy');
            
            // Обновляем центр и масштаб карты
            map.setCenter(coords);
            map.setBounds(bounds, {
                checkZoomRange: true
            });
        });
    }
    
    // Отображение существующих локаций на карте
    function showLocationsOnMap(subscriptionId) {
        const locations = userLocations[subscriptionId] || [];
        
        // Добавляем полигоны на карту
        locations.forEach(location => {
            const locationPolygon = new ymaps.Polygon([location.polygon_coordinates], {
                hintContent: location.name
            }, {
                fillColor: '#00FF0022',
                strokeColor: '#0000FF',
                strokeWidth: 2
            });
            
            map.geoObjects.add(locationPolygon);
            
            // Если у локации есть центр, добавляем метку
            if (location.center_lat && location.center_lng) {
                const placemark = new ymaps.Placemark(
                    [location.center_lat, location.center_lng], 
                    { balloonContent: location.name },
                    { preset: 'islands#blueCircleDotIcon' }
                );
                map.geoObjects.add(placemark);
            }
        });
    }
    
    // Сброс формы добавления/редактирования локации
    function resetLocationForm() {
        locationNameInput.value = '';
        polygonCoordinates = [];
        editMode = false;
        editLocationId = null;
        
        if (map && polygon) {
            map.geoObjects.remove(polygon);
            polygon = null;
        }
        
        // Обновляем текст кнопок
        if (saveLocationBtn) {
            saveLocationBtn.textContent = 'Сохранить локацию';
        }
        
        // Обновляем через ссылку для совместимости
        if (submitLocationBtn && submitLocationBtn !== saveLocationBtn) {
            submitLocationBtn.textContent = 'Сохранить локацию';
        }
        
        if (cancelEditBtn) {
            cancelEditBtn.style.display = 'none';
        }
        
        // Очищаем параметры location_id и subscription_id из URL
        const currentUrl = new URL(window.location.href);
        currentUrl.searchParams.delete('location_id');
        currentUrl.searchParams.delete('subscription_id');
        window.history.pushState({}, '', currentUrl);
    }
    
    // Очистка нарисованного полигона без сброса формы
    function clearPolygon() {
        polygonCoordinates = [];
        
        if (map && polygon) {
            map.geoObjects.remove(polygon);
            polygon = null;
        }
        
        showSuccess('Полигон очищен. Вы можете начать рисовать новый полигон.');
    }
    
    // Добавляем обработчик для кнопки "Очистить полигон"
    function addClearPolygonButton() {
        // Находим существующую кнопку
        const clearPolygonBtn = document.getElementById('clear-polygon-btn');
        if (clearPolygonBtn) {
            // Добавляем обработчик события для существующей кнопки
            clearPolygonBtn.onclick = clearPolygon;
        }
    }
    
    // Редактирование локации
    function editLocation(location) {
        console.log('Запуск функции редактирования локации:', location);
        console.log('Текущие активные подписки:', activeSubscriptions);
        
        // Выходим, если локация не передана
        if (!location) {
            // Пробуем получить ID из URL
            const urlParams = new URLSearchParams(window.location.search);
            const locationId = urlParams.get('location_id');
            
            if (!locationId) {
                console.warn('ID локации не найден в URL, выходим из функции редактирования');
                return;
            }
            
            // Получаем подписку из URL, если есть
            const subscriptionId = urlParams.get('subscription_id');
            console.log('ID из URL - локация:', locationId, 'подписка:', subscriptionId);
            
            // Проверяем, что у нас загружены активные подписки
            if (!activeSubscriptions || !Array.isArray(activeSubscriptions) || activeSubscriptions.length === 0) {
                console.error('Нет загруженных активных подписок, запрашиваем их...');
                // Загружаем подписки и затем перезапускаем редактирование
                getActiveSubscriptions().then(() => {
                    console.log('Подписки загружены, повторно запускаем редактирование');
                    editLocation(null);
                }).catch(error => {
                    console.error('Не удалось загрузить подписки:', error);
                    showError('Не удалось загрузить данные о подписках. Пожалуйста, обновите страницу.');
                });
                return;
            }
            
            // Ищем локацию по ID среди всех подписок
            let foundLocation = null;
            
            // Сначала проверим, загружены ли локации для указанной подписки
            if (subscriptionId) {
                if (!userLocations[subscriptionId]) {
                    console.log('Локации для подписки', subscriptionId, 'еще не загружены, загружаем...');
                    // Если локации для этой подписки не загружены, загружаем их
                    getUserLocationsBySubscription(subscriptionId).then(() => {
                        // Устанавливаем значение выпадающего списка
                        subscriptionSelect.value = subscriptionId;
                        // После загрузки локаций повторно запускаем редактирование
                        editLocation(null);
                    }).catch(error => {
                        console.error('Не удалось загрузить локации для подписки:', error);
                        showError('Не удалось загрузить данные о локациях. Пожалуйста, обновите страницу.');
                    });
                    return;
                }
                
                // Если локации загружены, ищем нужную
                foundLocation = userLocations[subscriptionId].find(loc => loc.id == locationId);
            } else {
                // Если подписка не указана, проверяем все загруженные локации
                for (const subId in userLocations) {
                    const locInSub = userLocations[subId].find(loc => loc.id == locationId);
                    if (locInSub) {
                        foundLocation = locInSub;
                        break;
                    }
                }
                
                // Если локация не найдена, возможно, её подписка не загружена
                if (!foundLocation) {
                    // Загружаем локации для всех активных подписок
                    const loadingPromises = activeSubscriptions.map(sub => {
                        if (!userLocations[sub.id]) {
                            return getUserLocationsBySubscription(sub.id);
                        }
                        return Promise.resolve();
                    });
                    
                    Promise.all(loadingPromises).then(() => {
                        // Повторно ищем локацию после загрузки всех данных
                        for (const subId in userLocations) {
                            const locInSub = userLocations[subId].find(loc => loc.id == locationId);
                            if (locInSub) {
                                editLocation(locInSub);
                                return;
                            }
                        }
                        
                        showError(`Локация с ID ${locationId} не найдена`);
                    }).catch(error => {
                        console.error('Ошибка при загрузке данных о локациях:', error);
                        showError('Произошла ошибка при загрузке данных. Пожалуйста, обновите страницу.');
                    });
                    
                    return;
                }
            }
            
            if (!foundLocation) {
                showError(`Локация с ID ${locationId} не найдена`);
                return;
            }
            
            location = foundLocation;
        }
        
        console.log('Редактируем локацию:', location);
        
        // Проверяем, существует ли подписка локации в активных подписках
        const subscriptionExists = activeSubscriptions.some(sub => sub.id == location.subscription_id);
        if (!subscriptionExists) {
            console.error('Подписка локации не найдена в активных подписках:', location.subscription_id);
            showError('Подписка, к которой относится эта локация, не найдена в ваших активных подписках.');
            return;
        }
        
        // Переключаем на подписку локации
        subscriptionSelect.value = location.subscription_id;
        console.log('Установлено значение списка подписок:', location.subscription_id);
        
        // Если встречаем локацию с другой подпиской, обновляем карту
        if (subscriptionSelect.value !== currentSubscriptionId) {
            console.log('Подписка изменилась, обновляем карту из', currentSubscriptionId, 'в', subscriptionSelect.value);
            // При изменении подписки карта обновится автоматически
            handleSubscriptionChange();
            
            // После обновления карты продолжаем редактирование
            setTimeout(() => {
                continueEditing(location);
            }, 500);
        } else {
            // Если подписка не изменилась, продолжаем редактирование сразу
            continueEditing(location);
        }
    }
    
    // Вспомогательная функция для продолжения редактирования после обновления карты
    function continueEditing(location) {
        // Устанавливаем режим редактирования
        editMode = true;
        editLocationId = location.id;
        
        // Заполняем форму данными локации
        locationNameInput.value = location.name;
        
        // Показываем кнопку отмены редактирования
        if (cancelEditBtn) {
            cancelEditBtn.style.display = 'inline-block';
        }
        
        // Изменяем текст кнопки сохранения
        if (saveLocationBtn) {
            saveLocationBtn.textContent = 'Сохранить изменения';
        }
        
        if (submitLocationBtn && submitLocationBtn !== saveLocationBtn) {
            submitLocationBtn.textContent = 'Сохранить изменения';
        }
        
        // Очищаем текущий полигон, если он есть
        clearPolygon();
        
        // Создаем полигон с координатами из локации
        polygonCoordinates = location.polygon_coordinates;
        
        // Проверяем, что map инициализирован перед созданием полигона
        if (!map) {
            console.error('Карта не инициализирована');
            showError('Произошла ошибка: карта не загружена. Пожалуйста, обновите страницу.');
            return;
        }
        
        try {
            polygon = new ymaps.Polygon([polygonCoordinates], {
                hintContent: location.name
            }, {
                fillColor: '#4361ee33',
                strokeColor: '#4361ee',
                strokeWidth: 3,
                strokeStyle: 'solid',
                editorDrawingCursor: "crosshair",
                editorMaxPoints: 50,
                draggable: false
            });
            
            // Добавляем полигон на карту
            map.geoObjects.add(polygon);
            
            // Включаем режим редактирования полигона
            if (polygon.editor) {
                polygon.editor.startEditing();
            } else {
                console.warn('Редактор полигона недоступен');
            }
            
            // Центрируем карту на полигоне
            map.setBounds(polygon.geometry.getBounds(), {
                checkZoomRange: true,
                zoomMargin: 50 // Добавляем небольшой отступ
            });
            
            // Показываем сообщение
            showSuccess(`Редактирование локации "${location.name}"`);
        } catch (error) {
            console.error('Ошибка при создании полигона:', error);
            showError('Произошла ошибка при создании полигона. Пожалуйста, обновите страницу и попробуйте снова.');
        }
    }
    
    // Подтверждение удаления локации
    function confirmDeleteLocation(locationId) {
        if (confirm('Вы уверены, что хотите удалить эту локацию?')) {
            deleteLocation(locationId);
        }
    }
    
    // Удаление локации
    async function deleteLocation(locationId) {
        try {
            toggleLoading(true);
            
            // Формируем URL с ID как URLParam, а не как параметр запроса
            const url = `/api/v1/location-polygons/${locationId}`;
            
            // Добавляем ID в тело запроса для совместимости
            const response = await fetch(url, {
                method: 'DELETE',
                headers: getAuthHeaders()
                // Убираем body, так как метод DELETE обычно не имеет тела,
                // а ID передается в URL уже
            });
            
            if (!response.ok) {
                const errorData = await response.json().catch(() => null);
                const errorMessage = errorData?.message || 'Не удалось удалить локацию';
                throw new Error(errorMessage);
            }
            
            const data = await response.json();
            
            if (data.code === 200 && data.status === 'success') {
                showSuccess('Локация успешно удалена');
                
                // Обновляем список локаций
                const subscriptionId = subscriptionSelect.value;
                await getUserLocationsBySubscription(subscriptionId);
                
                // Обновляем отображение локаций на карте
                updateMapForSubscription(subscriptionId);
            } else {
                throw new Error(data.message || 'Ошибка при удалении локации');
            }
        } catch (error) {
            showError(error.message);
        } finally {
            toggleLoading(false);
        }
    }
    
    // Проверка, находится ли полигон в границах локации подписки
    function checkPolygonInSubscriptionBounds() {
        const subscriptionId = subscriptionSelect.value;
        const subscription = activeSubscriptions.find(s => s.id == subscriptionId);
        
        console.log('Проверка границ полигона для подписки:', subscription);
        console.log('Координаты полигона:', polygonCoordinates);
        
        if (!subscription || !subscription.location || !subscription.location.bounds) {
            console.warn('У подписки нет границ, пропускаем проверку');
            // Если у подписки нет границ, разрешаем создание полигона без ограничений
            return true;
        }
        
        // Получаем границы и преобразуем их в числа
        const bounds = subscription.location.bounds;
        const numericBounds = {
            north: parseFloat(bounds.north),
            south: parseFloat(bounds.south),
            east: parseFloat(bounds.east),
            west: parseFloat(bounds.west)
        };
        
        console.log('Границы локации подписки (числовые):', numericBounds);
        
        // Проверяем валидность границ
        if (!numericBounds || 
            isNaN(numericBounds.north) || isNaN(numericBounds.south) || 
            isNaN(numericBounds.east) || isNaN(numericBounds.west) ||
            numericBounds.north <= numericBounds.south || 
            numericBounds.east <= numericBounds.west || 
            numericBounds.north > 90 || numericBounds.south < -90 || 
            numericBounds.east > 180 || numericBounds.west < -180) {
            console.warn('Невалидные границы локации, пропускаем проверку');
            return true;
        }
        
        // Проверяем каждую точку полигона
        let allPointsInBounds = true;
        let outOfBoundsPoints = [];
        
        for (let i = 0; i < polygonCoordinates.length; i++) {
            // В Яндекс.Картах координаты точек полигона: [широта, долгота]
            const lat = parseFloat(polygonCoordinates[i][0]); // Широта
            const lng = parseFloat(polygonCoordinates[i][1]); // Долгота
            
            // Проверяем, чтобы координаты были в правильном диапазоне
            const isLatInRange = lat >= -90 && lat <= 90;
            const isLngInRange = lng >= -180 && lng <= 180;
            
            if (!isLatInRange || !isLngInRange || isNaN(lat) || isNaN(lng)) {
                console.error(`Невалидные координаты точки ${i+1}:`, [lat, lng]);
                continue;
            }
            
            // Проверяем, находится ли точка в границах локации
            // Точка должна быть ВНУТРИ границ - точно такая же проверка, как при клике
            const northOk = lat <= numericBounds.north;  // Широта меньше или равна северной границе
            const southOk = lat >= numericBounds.south;  // Широта больше или равна южной границе
            const eastOk = lng <= numericBounds.east;    // Долгота меньше или равна восточной границе
            const westOk = lng >= numericBounds.west;    // Долгота больше или равна западной границе
            
            const pointInBounds = northOk && southOk && eastOk && westOk;
            
            if (!pointInBounds) {
                console.error(`Точка полигона ${i+1} вне границ локации:`, {
                    point: [lat, lng],
                    bounds: numericBounds,
                    checks: { northOk, southOk, eastOk, westOk }
                });
                
                outOfBoundsPoints.push({
                    index: i,
                    point: [lat, lng]
                });
                
                allPointsInBounds = false;
            }
        }
        
        // Если есть точки вне границ, показываем их на карте
        if (!allPointsInBounds && map) {
            // Очищаем предыдущие точки ошибок (если они были)
            const errorPoints = [];
            
            outOfBoundsPoints.forEach(pointInfo => {
                const errorPoint = new ymaps.Placemark(pointInfo.point, {
                    hintContent: `Точка ${pointInfo.index + 1} вне границ локации`,
                    balloonContent: `
                        <div>
                            <h5>Точка вне границ</h5>
                            <p>Координаты: [${pointInfo.point[0].toFixed(4)}, ${pointInfo.point[1].toFixed(4)}]</p>
                            <p>Границы локации:</p>
                            <p>С: ${numericBounds.north.toFixed(4)}, Ю: ${numericBounds.south.toFixed(4)}</p>
                            <p>В: ${numericBounds.east.toFixed(4)}, З: ${numericBounds.west.toFixed(4)}</p>
                        </div>
                    `
                }, {
                    preset: 'islands#redDotIcon',
                    zIndex: 1000
                });
                
                map.geoObjects.add(errorPoint);
                errorPoints.push(errorPoint);
            });
            
            // Показываем подробное сообщение об ошибке
            showError(`${outOfBoundsPoints.length} точек полигона выходят за пределы локации подписки. 
                      Эти точки отмечены красным цветом на карте. 
                      Пожалуйста, переместите их в пределы границ локации (отмечены оранжевым прямоугольником).`);
                      
            // Удаляем точки с ошибками через 10 секунд
            setTimeout(() => {
                errorPoints.forEach(point => {
                    map.geoObjects.remove(point);
                });
            }, 10000);
        }
        
        return allPointsInBounds;
    }
    
    // Проверка пересечения полигонов
    function checkPolygonIntersection() {
        const subscriptionId = subscriptionSelect.value;
        const locations = userLocations[subscriptionId] || [];
        
        // Если нет других локаций, то пересечений нет
        if (locations.length === 0) {
            return true;
        }
        
        try {
            // Создаем полигон из текущих координат для проверки, добавляем его на карту
            const currentPolygon = new ymaps.Polygon([polygonCoordinates], {}, {
                fillColor: '#00000000', // Прозрачный полигон
                strokeColor: '#00000000', // Прозрачная граница
                strokeWidth: 1
            });
            
            // Добавляем полигон на карту (необходимо для расчета границ)
            map.geoObjects.add(currentPolygon);
            
            // Проверяем пересечение с каждой существующей локацией
            for (let i = 0; i < locations.length; i++) {
                // Пропускаем текущую редактируемую локацию
                if (editMode && locations[i].id === editLocationId) {
                    continue;
                }
                
                // Создаем полигон для проверки, добавляем его на карту
                const existingPolygon = new ymaps.Polygon([locations[i].polygon_coordinates], {}, {
                    fillColor: '#00000000', // Прозрачный полигон
                    strokeColor: '#00000000', // Прозрачная граница
                    strokeWidth: 1
                });
                
                // Добавляем полигон на карту
                map.geoObjects.add(existingPolygon);
                
                // Проверяем пересечение полигонов
                try {
                    // Получаем границы полигонов
                    const currentBounds = currentPolygon.geometry.getBounds();
                    const existingBounds = existingPolygon.geometry.getBounds();
                    
                    // Проверка на пересечение границ
                    const intersection = ymaps.util.bounds.intersection(currentBounds, existingBounds);
                    
                    // Удаляем временный полигон
                    map.geoObjects.remove(existingPolygon);
                    
                    if (intersection) {
                        // Выделяем пересекающийся полигон
                        const highlightPolygon = new ymaps.Polygon([locations[i].polygon_coordinates], {
                            hintContent: `Пересечение с локацией "${locations[i].name}"`
                        }, {
                            fillColor: '#FF000055',
                            strokeColor: '#FF0000',
                            strokeWidth: 3,
                            zIndex: 999
                        });
                        
                        map.geoObjects.add(highlightPolygon);
                        
                        // Удалим выделение через 5 секунд
                        setTimeout(() => {
                            map.geoObjects.remove(highlightPolygon);
                        }, 5000);
                        
                        // Показываем ошибку
                        showError(`Ваш полигон пересекается с существующей локацией "${locations[i].name}". Пожалуйста, измените границы.`);
                        
                        // Удаляем временный полигон перед возвратом
                        map.geoObjects.remove(currentPolygon);
                        return false;
                    }
                } catch (error) {
                    console.error('Ошибка при проверке пересечения полигонов:', error);
                    // Удаляем временный полигон в случае ошибки
                    map.geoObjects.remove(existingPolygon);
                }
            }
            
            // Удаляем временный полигон после всех проверок
            map.geoObjects.remove(currentPolygon);
            return true;
        } catch (error) {
            console.error('Ошибка в функции checkPolygonIntersection:', error);
            return true; // В случае ошибки разрешаем сохранение
        }
    }
    
    // Обработчик отправки формы добавления/редактирования локации
    locationForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const locationName = locationNameInput.value.trim();
        const subscriptionId = subscriptionSelect.value;
        
        // Проверяем, заполнены ли все необходимые поля
        if (!locationName) {
            showError('Введите название локации');
            return;
        }
        
        if (!subscriptionId) {
            showError('Выберите подписку');
            return;
        }
        
        if (polygonCoordinates.length < 3) {
            showError('Нарисуйте полигон на карте (минимум 3 точки)');
            return;
        }
        
        // Если используется полигон из редактора Яндекс.Карт, обновим массив координат
        try {
            if (polygon && polygon.editor && typeof polygon.editor.state === 'object' && 
                polygon.editor.state.get && polygon.editor.state.get('editing')) {
                // Получаем координаты из редактируемого полигона
                polygonCoordinates = polygon.geometry.getCoordinates()[0];
            }
        } catch (error) {
            console.error('Ошибка при получении координат из редактора полигона:', error);
        }
        
        // Обновляем массив координат в консоли для отладки
        console.log('Координаты полигона перед сохранением:', polygonCoordinates);
        
        // Проверяем, что полигоны не пересекаются
        if (!checkPolygonIntersection()) {
            return;
        }
        
        // Проверяем, что полигон находится в границах локации подписки
        if (!checkPolygonInSubscriptionBounds()) {
            // Сообщение об ошибке уже показано в функции checkPolygonInSubscriptionBounds
            // Визуально подсвечиваем выбранную подписку
            visualizeSubscriptionBounds(subscriptionId);
            return;
        }
        
        // Формируем данные для отправки
        const locationData = {
            name: locationName,
            subscription_id: subscriptionId,
            polygon_coordinates: polygonCoordinates
        };
        
        try {
            toggleLoading(true);
            
            let url = '/api/v1/location-polygons';
            let method = 'POST';
            
            // Если это редактирование, изменяем URL и метод
            if (editMode && editLocationId) {
                url = `/api/v1/location-polygons/${editLocationId}`;
                method = 'PUT';
            }
            
            console.log(`Отправка ${method}-запроса по URL: ${url}`, locationData);
            
            const response = await fetch(url, {
                method: method,
                headers: getAuthHeaders(),
                body: JSON.stringify(locationData)
            });
            
            if (!response.ok) {
                // Попытка получить детали ошибки
                let errorDetails = '';
                try {
                    const errorData = await response.json();
                    errorDetails = errorData.message || '';
                } catch (e) {
                    // Если не удалось прочитать JSON, игнорируем
                }
                
                throw new Error(`Не удалось сохранить локацию. ${errorDetails}`);
            }
            
            const data = await response.json();
            
            if ((data.code === 200 || data.code === 201) && data.status === 'success') {
                showSuccess(editMode ? 'Локация успешно обновлена' : 'Локация успешно создана');
                
                // Сбрасываем форму
                resetLocationForm();
                
                // Обновляем список локаций
                await getUserLocationsBySubscription(subscriptionId);
                
                // Обновляем отображение локаций на карте
                updateMapForSubscription(subscriptionId);
            } else {
                throw new Error(data.message || 'Ошибка при сохранении локации');
            }
        } catch (error) {
            showError(error.message);
        } finally {
            toggleLoading(false);
        }
    });
    
    // Обработчик кнопки отмены редактирования
    cancelEditBtn.addEventListener('click', function(e) {
        e.preventDefault();
        resetLocationForm();
    });
    
    // Функция для создания границ по названию локации
    async function updateLocationBounds(locationName) {
        if (!locationName || !map) return null;
        
        try {
            // Пытаемся найти локацию по названию через геокодирование
            const res = await new Promise((resolve, reject) => {
                ymaps.geocode(locationName, { results: 1 })
                    .then(resolve)
                    .catch(reject);
            });
            
            if (res.geoObjects.getLength() === 0) {
                console.warn('Геокодирование не нашло локацию:', locationName);
                return null;
            }
            
            const firstGeoObject = res.geoObjects.get(0);
            const coords = firstGeoObject.geometry.getCoordinates();
            const bounds = firstGeoObject.properties.get('boundedBy');
            
            if (!bounds) {
                console.warn('Не удалось получить границы для локации:', locationName);
                return null;
            }
            
            // Формируем объект с границами в формате, который ожидается в API
            const boundsObj = {
                north: bounds[1][0],  // Северная широта (верхняя)
                south: bounds[0][0],  // Южная широта (нижняя)
                east: bounds[1][1],   // Восточная долгота (правая)
                west: bounds[0][1]    // Западная долгота (левая)
            };
            
            // Для отладки выводим информацию о границах
            console.log(`Границы для локации "${locationName}":`, boundsObj);
            
            return {
                center_lat: coords[0],
                center_lng: coords[1],
                bounds: boundsObj
            };
        } catch (error) {
            console.error('Ошибка при геокодировании:', error);
            return null;
        }
    }
    
    // Функция для фокусировки на выбранной локации
    function focusOnLocation(location) {
        if (!location || !map) return;
        
        // Очищаем текущий полигон, если он есть
        resetLocationForm();
        
        // Очищаем все объекты на карте
        map.geoObjects.removeAll();
        
        // Создаем полигон с координатами из локации с особым стилем
        const focusedPolygon = new ymaps.Polygon([location.polygon_coordinates], {
            hintContent: location.name
        }, {
            fillColor: '#4361ee55', // Используем основной цвет с прозрачностью
            strokeColor: '#4361ee',
            strokeWidth: 3,
            strokeStyle: 'solid'
        });
        
        // Добавляем полигон на карту
        map.geoObjects.add(focusedPolygon);
        
        // Центрируем карту на полигоне
        map.setBounds(focusedPolygon.geometry.getBounds(), {
            checkZoomRange: true,
            zoomMargin: 50 // Добавляем небольшой отступ
        });
        
        // Выделяем карточку локации в списке
        const locationItems = document.querySelectorAll('.location-item');
        locationItems.forEach(item => {
            item.classList.remove('active-location');
            if (item.getAttribute('data-location-id') == location.id) {
                item.classList.add('active-location');
            }
        });
        
        // Показываем информацию о выбранной локации
        showSuccess(`Выбрана локация "${location.name}"`);
    }
    
    // Функция для получения точных границ регионов
    async function getRegionBoundaries(regionName) {
        try {
            // Загружаем модуль регионов
            await ymaps.modules.require(['regions', 'geoQuery'], function (regions, geoQuery) {
                // Загружаем регионы России
                regions.load('RU', {
                    lang: 'ru',
                    quality: 2
                }).then(function (result) {
                    // Ищем нужный регион по имени
                    const foundRegions = geoQuery(result.geoObjects)
                        .search('properties.name = "' + regionName + '"')
                        .getAll();
                    
                    if (foundRegions.length > 0) {
                        const region = foundRegions[0];
                        // Получаем координаты границ региона
                        const geometry = region.geometry;
                        const coordinates = geometry.getCoordinates();
                        const bounds = geometry.getBounds();
                        
                        console.log(`Найдены границы региона ${regionName}:`, {
                            coordinates: coordinates,
                            bounds: bounds
                        });
                        
                        // Создаем полигон с границами региона
                        const regionPolygon = new ymaps.Polygon(coordinates, {
                            hintContent: regionName
                        }, {
                            fillColor: '#00FF0022',
                            strokeColor: '#0000FF',
                            strokeWidth: 2,
                            strokeStyle: 'dash'
                        });
                        
                        // Добавляем полигон на карту
                        map.geoObjects.add(regionPolygon);
                        
                        // Центрируем карту на регионе
                        map.setBounds(bounds, {
                            checkZoomRange: true,
                            zoomMargin: 50
                        });
                        
                        // Показываем информацию о регионе
                        showSuccess(`Загружены точные границы региона "${regionName}"`);
                        
                        // Возвращаем данные для использования в других функциях
                        return {
                            polygon: regionPolygon,
                            bounds: {
                                north: bounds[1][0],
                                south: bounds[0][0],
                                east: bounds[1][1],
                                west: bounds[0][1]
                            },
                            coordinates: coordinates
                        };
                    } else {
                        showError(`Регион "${regionName}" не найден`);
                        return null;
                    }
                });
            });
        } catch (error) {
            console.error('Ошибка при получении границ региона:', error);
            showError('Не удалось загрузить границы региона. Попробуйте еще раз позже.');
            return null;
        }
    }
    
    // Добавить кнопку для получения точных границ региона
    function addLoadRegionButton() {
        // Создаем блок с кнопкой загрузки границ
        const loadRegionBlock = document.createElement('div');
        loadRegionBlock.className = 'mt-3';
        loadRegionBlock.innerHTML = `
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Точные границы регионов</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="region-name" class="form-label">Название региона</label>
                        <input type="text" class="form-control" id="region-name" placeholder="Например: Московская область">
                    </div>
                    <button type="button" class="btn btn-primary" id="load-region-btn">
                        <i class="bi bi-geo-alt me-1"></i> Загрузить границы
                    </button>
                </div>
            </div>
        `;
        
        // Находим блок для вставки (после формы добавления локации)
        const locationFormRow = document.querySelector('.row.mt-4');
        if (locationFormRow) {
            locationFormRow.parentNode.insertBefore(loadRegionBlock, locationFormRow.nextSibling);
            
            // Добавляем обработчик клика на кнопку
            document.getElementById('load-region-btn').addEventListener('click', function() {
                const regionName = document.getElementById('region-name').value.trim();
                if (regionName) {
                    getRegionBoundaries(regionName);
                } else {
                    showError('Введите название региона');
                }
            });
        }
    }
    
    // Инициализация страницы
    async function initPage() {
        console.log('Начинаем инициализацию страницы...');
        
        try {
            // Проверяем, есть ли в URL параметры
            const urlParams = new URLSearchParams(window.location.search);
            const locationId = urlParams.get('location_id');
            const subscriptionId = urlParams.get('subscription_id');
            
            console.log('Параметры URL:', { locationId, subscriptionId });
            
            // Получаем активные подписки
            console.log('Загружаем активные подписки...');
            await getActiveSubscriptions();
            
            console.log('Активные подписки получены, количество:', activeSubscriptions.length);
            
            // Проверка загрузки подписок
            if (!activeSubscriptions || activeSubscriptions.length === 0) {
                console.warn('Нет активных подписок');
                showError('У вас нет активных подписок для настройки локаций.');
                return;
            }
            
            // Удаляем существующие обработчики событий, если страница уже была инициализирована
            const existingClearBtn = document.getElementById('clear-polygon-btn');
            if (existingClearBtn) {
                existingClearBtn.onclick = null;
            }
            
            // Добавляем обработчики кнопок
            addClearPolygonButton();
            addLoadRegionButton();
            
            // Если есть ID локации в URL, инициализируем редактирование
            if (locationId) {
                console.log(`Обнаружен ID локации в URL: ${locationId}, запускаем редактирование...`);
                // После загрузки подписок вызываем функцию редактирования с null, 
                // чтобы она сама получила данные из URL
                editLocation(null);
            } 
            // Если есть только ID подписки, переключаемся на нее
            else if (subscriptionId) {
                console.log(`Обнаружен ID подписки в URL: ${subscriptionId}, переключаемся на нее...`);
                // Проверяем, что такая подписка существует
                const subscriptionExists = activeSubscriptions.some(s => s.id == subscriptionId);
                if (subscriptionExists) {
                    subscriptionSelect.value = subscriptionId;
                    // Запускаем обработчик изменения, чтобы обновить интерфейс
                    await handleSubscriptionChange();
                } else {
                    console.warn(`Подписка с ID ${subscriptionId} не найдена среди активных`);
                    showError(`Подписка с ID ${subscriptionId} не найдена среди активных. Будет выбрана первая доступная подписка.`);
                    // Выбираем первую доступную подписку
                    if (activeSubscriptions.length > 0) {
                        subscriptionSelect.value = activeSubscriptions[0].id;
                        await handleSubscriptionChange();
                    }
                }
            } 
            // Если нет параметров в URL, но есть подписки, выбираем первую
            else if (activeSubscriptions.length > 0) {
                console.log('Нет параметров в URL, выбираем первую доступную подписку');
                subscriptionSelect.value = activeSubscriptions[0].id;
                await handleSubscriptionChange();
            }
            
            console.log('Инициализация страницы завершена успешно');
        } catch (error) {
            console.error('Ошибка при инициализации страницы:', error);
            showError('Произошла ошибка при загрузке страницы. Пожалуйста, обновите браузер и попробуйте снова.');
        }
    }
    
    // Запускаем инициализацию при загрузке страницы
    initPage();
}); 