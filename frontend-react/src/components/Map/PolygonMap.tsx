import { useState, useCallback, useRef, useEffect } from 'react';
import { YMaps, Map, Polygon, ZoomControl, GeolocationControl } from '@pbe/react-yandex-maps';
import type { LocationPolygon } from '../../services/api';
import './PolygonMap.css';

interface PolygonMapProps {
  polygons: LocationPolygon[];
  center?: [number, number];
  zoom?: number;
  onPolygonCreate?: (coordinates: [number, number][], name: string) => void;
  onPolygonUpdate?: (id: number, coordinates: [number, number][]) => void;
  onPolygonDelete?: (id: number) => void;
  editable?: boolean;
}

// Цвета для полигонов
const POLYGON_COLORS = [
  '#849873', // primary green
  '#3b82f6', // blue
  '#f59e0b', // amber
  '#ef4444', // red
  '#8b5cf6', // purple
  '#06b6d4', // cyan
];

const YANDEX_API_KEY = import.meta.env.VITE_YANDEX_MAPS_API_KEY || '';

export const PolygonMap = ({
  polygons,
  center = [55.7558, 37.6173], // Москва по умолчанию
  zoom = 10,
  onPolygonCreate,
  onPolygonUpdate,
  onPolygonDelete,
  editable = true,
}: PolygonMapProps) => {
  const mapRef = useRef<any>(null);
  const [isDrawing, setIsDrawing] = useState(false);
  const [drawingCoords, setDrawingCoords] = useState<[number, number][]>([]);
  const [isAddingPolygon, setIsAddingPolygon] = useState(false);
  const [newPolygonName, setNewPolygonName] = useState('');
  const [selectedPolygonId, setSelectedPolygonId] = useState<number | null>(null);
  const [editingPolygonId, setEditingPolygonId] = useState<number | null>(null);
  const [mapState, setMapState] = useState({ center, zoom });
  const [searchQuery, setSearchQuery] = useState('');

  // Обновляем центр карты при изменении props
  useEffect(() => {
    setMapState({ center, zoom });
  }, [center, zoom]);

  // Начать рисование
  const startDrawing = useCallback(() => {
    setIsDrawing(true);
    setDrawingCoords([]);
    setSelectedPolygonId(null);
  }, []);

  // Отменить рисование
  const cancelDrawing = useCallback(() => {
    setIsDrawing(false);
    setDrawingCoords([]);
  }, []);

  // Клик по карте при рисовании
  const handleMapClick = useCallback((e: any) => {
    if (!isDrawing) return;
    
    const coords = e.get('coords') as [number, number];
    setDrawingCoords(prev => [...prev, coords]);
  }, [isDrawing]);

  // Завершить рисование
  const finishDrawing = useCallback(() => {
    if (drawingCoords.length < 3) {
      alert('Нужно минимум 3 точки для создания области');
      return;
    }
    
    setIsDrawing(false);
    setIsAddingPolygon(true);
  }, [drawingCoords]);

  // Подтверждение создания полигона
  const handleConfirmCreate = useCallback(() => {
    if (drawingCoords.length >= 3 && newPolygonName.trim() && onPolygonCreate) {
      onPolygonCreate(drawingCoords, newPolygonName.trim());
    }
    
    setIsAddingPolygon(false);
    setNewPolygonName('');
    setDrawingCoords([]);
  }, [drawingCoords, newPolygonName, onPolygonCreate]);

  // Отмена создания полигона
  const handleCancelCreate = useCallback(() => {
    setIsAddingPolygon(false);
    setNewPolygonName('');
    setDrawingCoords([]);
  }, []);

  // Удалить последнюю точку
  const removeLastPoint = useCallback(() => {
    setDrawingCoords(prev => prev.slice(0, -1));
  }, []);

  // Удалить полигон
  const handleDeletePolygon = useCallback((id: number) => {
    if (onPolygonDelete && confirm('Удалить эту область?')) {
      onPolygonDelete(id);
      setSelectedPolygonId(null);
    }
  }, [onPolygonDelete]);

  // Фокус на полигоне
  const focusOnPolygon = useCallback((polygon: LocationPolygon) => {
    setSelectedPolygonId(polygon.id);
    if (polygon.center_lat && polygon.center_lng) {
      setMapState({
        center: [polygon.center_lat, polygon.center_lng],
        zoom: 12,
      });
    }
  }, []);

  return (
    <div className="polygon-map-container">
      <YMaps query={{ apikey: YANDEX_API_KEY, lang: 'ru_RU' }}>
        <Map
          state={mapState}
          className="polygon-map"
          onClick={handleMapClick}
          instanceRef={mapRef}
          options={{
            suppressMapOpenBlock: true,
          }}
        >
          <ZoomControl options={{ position: { right: 10, top: 10 } }} />
          <GeolocationControl options={{ position: { right: 10, top: 50 } }} />

          {/* Существующие полигоны */}
          {polygons.map((polygon, index) => (
            <Polygon
              key={polygon.id}
              geometry={[polygon.polygon_coordinates]}
              options={{
                fillColor: POLYGON_COLORS[index % POLYGON_COLORS.length],
                fillOpacity: selectedPolygonId === polygon.id ? 0.5 : 0.3,
                strokeColor: POLYGON_COLORS[index % POLYGON_COLORS.length],
                strokeWidth: selectedPolygonId === polygon.id ? 3 : 2,
              }}
              onClick={() => setSelectedPolygonId(polygon.id)}
            />
          ))}

          {/* Рисуемый полигон */}
          {drawingCoords.length > 0 && (
            <Polygon
              geometry={[drawingCoords]}
              options={{
                fillColor: '#849873',
                fillOpacity: 0.2,
                strokeColor: '#849873',
                strokeWidth: 2,
                strokeStyle: 'dash',
              }}
            />
          )}
        </Map>
      </YMaps>

      {/* Панель инструментов */}
      {editable && !isAddingPolygon && (
        <div className="map-toolbar">
          {!isDrawing ? (
            <button className="toolbar-btn primary" onClick={startDrawing}>
              <span className="material-icons">add_location</span>
              Нарисовать область
            </button>
          ) : (
            <>
              <button 
                className="toolbar-btn success" 
                onClick={finishDrawing}
                disabled={drawingCoords.length < 3}
              >
                <span className="material-icons">check</span>
                Готово ({drawingCoords.length} точек)
              </button>
              <button 
                className="toolbar-btn" 
                onClick={removeLastPoint}
                disabled={drawingCoords.length === 0}
              >
                <span className="material-icons">undo</span>
                Удалить точку
              </button>
              <button className="toolbar-btn danger" onClick={cancelDrawing}>
                <span className="material-icons">close</span>
                Отмена
              </button>
            </>
          )}
        </div>
      )}

      {/* Диалог для ввода имени полигона */}
      {isAddingPolygon && (
        <div className="polygon-name-dialog">
          <div className="dialog-content">
            <h3>Новая область</h3>
            <p className="dialog-hint">Точек: {drawingCoords.length}</p>
            <input
              type="text"
              placeholder="Название области (например: Центр)"
              value={newPolygonName}
              onChange={(e) => setNewPolygonName(e.target.value.slice(0, 70))}
              maxLength={70}
              autoFocus
              onKeyDown={(e) => {
                if (e.key === 'Enter' && newPolygonName.trim()) {
                  handleConfirmCreate();
                }
                if (e.key === 'Escape') {
                  handleCancelCreate();
                }
              }}
            />
            <span className="char-counter">{newPolygonName.length}/70</span>
            <div className="dialog-actions">
              <button 
                className="btn btn-secondary" 
                onClick={handleCancelCreate}
              >
                Отмена
              </button>
              <button 
                className="btn btn-primary"
                onClick={handleConfirmCreate}
                disabled={!newPolygonName.trim()}
              >
                Сохранить
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Список полигонов */}
      {polygons.length > 0 && (
        <div className="polygon-list">
          <h4>Области ({polygons.length})</h4>
          {polygons.length > 3 && (
            <div className="polygon-search">
              <span className="material-icons">search</span>
              <input
                type="text"
                placeholder="Поиск..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
              />
              {searchQuery && (
                <button 
                  className="search-clear"
                  onClick={() => setSearchQuery('')}
                >
                  <span className="material-icons">close</span>
                </button>
              )}
            </div>
          )}
          <ul>
            {polygons
              .filter(p => !searchQuery || p.name.toLowerCase().includes(searchQuery.toLowerCase()))
              .map((polygon, index) => {
                // Находим оригинальный индекс для правильного цвета
                const originalIndex = polygons.findIndex(p => p.id === polygon.id);
                return (
                  <li 
                    key={polygon.id}
                    className={selectedPolygonId === polygon.id ? 'selected' : ''}
                  >
                    <div 
                      className="polygon-item-info"
                      onClick={() => focusOnPolygon(polygon)}
                    >
                      <span 
                        className="polygon-color" 
                        style={{ backgroundColor: POLYGON_COLORS[originalIndex % POLYGON_COLORS.length] }}
                      />
                      <span className="polygon-name">{polygon.name}</span>
                    </div>
                    {editable && (
                      <button 
                        className="polygon-delete-btn"
                        onClick={(e) => {
                          e.stopPropagation();
                          handleDeletePolygon(polygon.id);
                        }}
                        title="Удалить область"
                      >
                        <span className="material-icons">delete</span>
                      </button>
                    )}
                  </li>
                );
              })}
          </ul>
          {searchQuery && polygons.filter(p => p.name.toLowerCase().includes(searchQuery.toLowerCase())).length === 0 && (
            <div className="polygon-no-results">Ничего не найдено</div>
          )}
        </div>
      )}

      {/* Подсказка */}
      {editable && polygons.length === 0 && !isDrawing && !isAddingPolygon && (
        <div className="polygon-hint">
          <span className="material-icons">info</span>
          <span>Нажмите "Нарисовать область" и кликайте по карте для создания полигона</span>
        </div>
      )}

      {/* Подсказка при рисовании */}
      {isDrawing && (
        <div className="polygon-hint drawing">
          <span className="material-icons">touch_app</span>
          <span>Кликайте по карте для добавления точек. Минимум 3 точки.</span>
        </div>
      )}
    </div>
  );
};

export default PolygonMap;
