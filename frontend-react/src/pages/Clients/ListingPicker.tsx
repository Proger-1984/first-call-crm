import { useState, useCallback } from 'react';
import { listingsApi, clientsApi } from '../../services/api';
import { ConfirmDialog } from '../../components/UI/ConfirmDialog';
import './ListingPicker.css';

interface ListingPickerProps {
  clientId: number;
  onClose: () => void;
  onAdded: () => void;
}

interface ListingResult {
  id: number;
  title: string | null;
  price: number | null;
  address: string;
  url: string | null;
}

export function ListingPicker({ clientId, onClose, onAdded }: ListingPickerProps) {
  const [searchQuery, setSearchQuery] = useState('');
  const [results, setResults] = useState<ListingResult[]>([]);
  const [loading, setLoading] = useState(false);
  const [addingId, setAddingId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [searched, setSearched] = useState(false);

  // Диалог для ошибок
  const [dialog, setDialog] = useState<{
    title: string;
    message: string;
    variant?: 'danger' | 'warning' | 'info';
    onConfirm: () => void;
  } | null>(null);

  /** Поиск объявлений */
  const handleSearch = useCallback(async () => {
    const query = searchQuery.trim();
    if (!query) return;

    setLoading(true);
    setError(null);
    setSearched(true);
    try {
      // Используем POST /listings с фильтрами поиска
      // Если введён только число — ищем по ID, иначе — по адресу/заголовку
      const isNumeric = /^\d+$/.test(query);
      const params: Record<string, any> = {
        per_page: 20,
        page: 1,
      };
      if (isNumeric) {
        params.external_id = query;
      } else {
        params.search = query;
      }

      const response = await listingsApi.getAll(params as any);
      const listings = response.data?.data?.listings || [];
      setResults(listings.map((listing: any) => ({
        id: listing.id,
        title: listing.title || null,
        price: listing.price || null,
        address: listing.address || '',
        url: listing.url || null,
      })));
    } catch {
      setError('Ошибка поиска');
      setResults([]);
    } finally {
      setLoading(false);
    }
  }, [searchQuery]);

  /** Добавить объявление в подборку */
  const handleAdd = async (listingId: number) => {
    setAddingId(listingId);
    try {
      await clientsApi.addListing(clientId, listingId);
      // Убираем добавленное из результатов
      setResults(prev => prev.filter(item => item.id !== listingId));
      onAdded();
    } catch (err: any) {
      const message = err.response?.data?.message || 'Ошибка добавления';
      setDialog({ title: 'Ошибка', message, variant: 'danger', onConfirm: () => setDialog(null) });
    } finally {
      setAddingId(null);
    }
  };

  /** Форматирование цены */
  const formatPrice = (price: number | null): string => {
    if (!price) return '';
    return new Intl.NumberFormat('ru-RU').format(price) + ' ₽';
  };

  return (
    <div className="modal-overlay">
      <div className="listing-picker-modal">
        <div className="modal-header">
          <h2>Добавить объявление в подборку</h2>
          <button className="close-btn" onClick={onClose}>
            <span className="material-icons">close</span>
          </button>
        </div>

        <div className="listing-picker-content">
          {/* Поиск */}
          <div className="listing-search-row">
            <input
              type="text"
              className="listing-search-input"
              placeholder="Поиск по ID, адресу или заголовку..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
              autoFocus
            />
            <button
              className="btn-primary"
              onClick={handleSearch}
              disabled={loading || !searchQuery.trim()}
            >
              {loading ? 'Поиск...' : 'Найти'}
            </button>
          </div>

          {/* Результаты */}
          {error && <div className="listing-picker-error">{error}</div>}

          {results.length > 0 && (
            <div className="listing-picker-results">
              {results.map(listing => (
                <div key={listing.id} className="listing-picker-item">
                  <div className="listing-picker-info">
                    <div className="listing-picker-title">
                      {listing.title || `Объявление #${listing.id}`}
                    </div>
                    <div className="listing-picker-details">
                      {listing.price && <span className="listing-picker-price">{formatPrice(listing.price)}</span>}
                      {listing.address && <span className="listing-picker-address">{listing.address}</span>}
                    </div>
                  </div>
                  <button
                    className="btn-primary btn-sm"
                    onClick={() => handleAdd(listing.id)}
                    disabled={addingId === listing.id}
                  >
                    {addingId === listing.id ? '...' : 'Добавить'}
                  </button>
                </div>
              ))}
            </div>
          )}

          {searched && results.length === 0 && !loading && !error && (
            <div className="listing-picker-empty">Ничего не найдено</div>
          )}
        </div>
      </div>

      {/* Диалог для ошибок */}
      {dialog && (
        <ConfirmDialog
          title={dialog.title}
          message={dialog.message}
          variant={dialog.variant}
          onConfirm={dialog.onConfirm}
          onCancel={() => setDialog(null)}
        />
      )}
    </div>
  );
}

export default ListingPicker;
