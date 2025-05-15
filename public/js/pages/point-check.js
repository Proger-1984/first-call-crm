// Страница проверки точки на вхождение в локацию
document.addEventListener('DOMContentLoaded', function() {
    // Переменные для работы с Яндекс Картами
    let map, placemark;
    let selectedCoords = null;
    
    // HTML-элементы
    const addressSearchInput = document.getElementById('address-search');
    const searchButton = document.getElementById('search-button');
    const latitudeInput = document.getElementById('latitude');
    const longitudeInput = document.getElementById('longitude');
    const checkPointButton = document.getElementById('check-point');
    const resultsContainer = document.getElementById('results-container');
    const loadingIndicator = document.getElementById('loading-indicator');
    
    // Ваш JWT-токен
    const jwtToken = localStorage.getItem('access_token'); 
    
    // Проверяем наличие токена
    if (!jwtToken) {
        console.error('Токен авторизации не найден');
        // При загрузке страницы показываем сообщение об ошибке
        showError('Для доступа к этой странице необходимо авторизоваться');
        // Перенаправление на страницу авторизации через 2 секунды
        setTimeout(() => {
            window.location.href = '/telegram-auth.html';
        }, 2000);
        return;
    }
    
    // Функция для получения заголовков с токеном авторизации
    function getAuthHeaders() {
        return {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': `Bearer ${jwtToken}`
        };
    }
    
    // Инициализация страницы
    initPage();
    
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
        loadingIndicator.style.display = show ? 'flex' : 'none';
    }
    
    // Инициализация страницы
    async function initPage() {
        try {
            // Загружаем Яндекс Карты
            await loadYandexMapsAPI();
            
            // Инициализируем карту
            ymaps.ready(initYandexMap);
            
            // Добавляем обработчики событий
            searchButton.addEventListener('click', searchAddress);
            checkPointButton.addEventListener('click', checkPoint);
            
            // Добавляем обработчик нажатия Enter в поле поиска
            addressSearchInput.addEventListener('keypress', function(event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    searchAddress();
                }
            });
        } catch (error) {
            showError('Ошибка при инициализации страницы: ' + error.message);
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
    
    // Инициализация Яндекс Карты
    function initYandexMap() {
        try {
            // Создаем карту с центром в Москве по умолчанию
            map = new ymaps.Map('map', {
                center: [55.755814, 37.617635], // Москва
                zoom: 10,
                controls: ['zoomControl', 'searchControl', 'typeSelector', 'fullscreenControl']
            });
            
            // Добавляем поисковую строку на карту
            const searchControl = map.controls.get('searchControl');
            searchControl.options.set({
                noPlacemark: true,
                placeholderContent: 'Поиск на карте'
            });
            
            // Добавляем обработчик клика по карте
            map.events.add('click', function(e) {
                const coords = e.get('coords');
                setSelectedPoint(coords);
            });
            
            // Добавляем обработчик событий поискового контрола
            searchControl.events.add('resultshow', function() {
                const result = searchControl.getResult(0);
                if (result) {
                    const coords = result.geometry.getCoordinates();
                    setSelectedPoint(coords);
                }
            });
            
            // Получаем местоположение пользователя для первоначального центрирования карты
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        const coords = [position.coords.latitude, position.coords.longitude];
                        map.setCenter(coords, 14);
                    },
                    function(error) {
                        console.log('Ошибка определения местоположения:', error);
                    }
                );
            }
        } catch (error) {
            showError('Не удалось инициализировать карту: ' + error.message);
        }
    }
    
    // Функция поиска адреса
    function searchAddress() {
        const address = addressSearchInput.value.trim();
        if (!address) {
            showError('Введите адрес для поиска');
            return;
        }
        
        toggleLoading(true);
        
        // Геокодирование адреса
        ymaps.geocode(address, {
            results: 1
        }).then(function(res) {
            const firstGeoObject = res.geoObjects.get(0);
            
            if (!firstGeoObject) {
                showError('Адрес не найден. Попробуйте уточнить запрос.');
                toggleLoading(false);
                return;
            }
            
            // Получаем координаты найденного объекта
            const coords = firstGeoObject.geometry.getCoordinates();
            
            // Центрируем карту на найденном объекте
            map.setCenter(coords, 15);
            
            // Устанавливаем выбранную точку
            setSelectedPoint(coords);
            
            toggleLoading(false);
        }).catch(function(error) {
            showError('Ошибка при поиске адреса: ' + error.message);
            toggleLoading(false);
        });
    }
    
    // Установка выбранной точки на карте
    function setSelectedPoint(coords) {
        // Сохраняем координаты
        selectedCoords = coords;
        
        // Обновляем поля ввода
        latitudeInput.value = coords[0].toFixed(6);
        longitudeInput.value = coords[1].toFixed(6);
        
        // Удаляем предыдущую метку, если она есть
        if (placemark) {
            map.geoObjects.remove(placemark);
        }
        
        // Создаем новую метку
        placemark = new ymaps.Placemark(coords, {
            hintContent: 'Выбранная точка',
            balloonContent: `Координаты: ${coords[0].toFixed(6)}, ${coords[1].toFixed(6)}`
        }, {
            preset: 'islands#redDotIcon',
            draggable: true
        });
        
        // Добавляем метку на карту
        map.geoObjects.add(placemark);
        
        // Добавляем обработчик перетаскивания метки
        placemark.events.add('dragend', function() {
            const newCoords = placemark.geometry.getCoordinates();
            setSelectedPoint(newCoords);
        });
        
        // Показываем сообщение
        showSuccess('Точка выбрана. Нажмите "Проверить точку", чтобы проверить её вхождение в ваши локации.');
    }
    
    // Проверка точки на вхождение в локации пользователя
    async function checkPoint() {
        if (!selectedCoords) {
            showError('Сначала выберите точку на карте');
            return;
        }
        
        try {
            toggleLoading(true);
            
            // Отправляем запрос к API для проверки точки
            const response = await fetch('/api/v1/location-polygons/check-point', {
                method: 'POST',
                headers: getAuthHeaders(),
                body: JSON.stringify({
                    lat: selectedCoords[0],
                    lng: selectedCoords[1]
                })
            });
            
            if (!response.ok) {
                throw new Error('Не удалось проверить точку. Пожалуйста, попробуйте позже.');
            }
            
            const data = await response.json();
            
            if (data.code === 200 && data.status === 'success') {
                displayResults(data.data);
            } else {
                throw new Error(data.message || 'Произошла ошибка при проверке точки');
            }
        } catch (error) {
            showError(error.message);
        } finally {
            toggleLoading(false);
        }
    }
    
    // Отображение результатов проверки
    function displayResults(data) {
        resultsContainer.innerHTML = '';
        
        const pointInfo = document.createElement('div');
        pointInfo.className = 'mb-3';
        pointInfo.innerHTML = `
            <h6>Проверенная точка:</h6>
            <p class="mb-2">Широта: ${data.point.lat}</p>
            <p>Долгота: ${data.point.lng}</p>
            <hr>
        `;
        
        const resultStatus = document.createElement('div');
        resultStatus.className = 'mb-3';
        
        if (data.in_locations) {
            resultStatus.innerHTML = `
                <div class="alert alert-success">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    Точка входит в ваши локации!
                </div>
            `;
        } else {
            resultStatus.innerHTML = `
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    Точка не входит ни в одну из ваших локаций.
                </div>
            `;
        }
        
        resultsContainer.appendChild(pointInfo);
        resultsContainer.appendChild(resultStatus);
        
        // Если есть совпадающие локации, отображаем их
        if (data.matching_locations && data.matching_locations.length > 0) {
            const locationsContainer = document.createElement('div');
            locationsContainer.innerHTML = '<h6>Локации, содержащие точку:</h6>';
            
            data.matching_locations.forEach(location => {
                const locationItem = document.createElement('div');
                locationItem.className = 'location-item location-match card mb-2';
                
                locationItem.innerHTML = `
                    <div class="card-body py-2">
                        <h6 class="mb-1">${location.name}</h6>
                        <p class="card-text text-muted mb-0 small">${location.subscription_name}</p>
                    </div>
                `;
                
                locationsContainer.appendChild(locationItem);
            });
            
            resultsContainer.appendChild(locationsContainer);
        }
        
        // Показываем сообщение с результатом
        if (data.in_locations) {
            showSuccess('Точка входит в ваши локации!');
        } else {
            showSuccess('Точка не входит ни в одну из ваших локаций.');
        }
    }
}); 