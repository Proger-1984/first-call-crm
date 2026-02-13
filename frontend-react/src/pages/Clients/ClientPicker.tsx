import { useState, useCallback } from 'react';
import { clientsApi } from '../../services/api';
import { CLIENT_TYPE_LABELS } from '../../types/client';
import type { Client, ClientType } from '../../types/client';
import './ClientPicker.css';

interface ClientPickerProps {
  listingId: number;
  onClose: () => void;
  onAdded: () => void;
}

interface ClientResult {
  id: number;
  name: string;
  phone: string | null;
  client_type: ClientType;
}

export function ClientPicker({ listingId, onClose, onAdded }: ClientPickerProps) {
  const [searchQuery, setSearchQuery] = useState('');
  const [results, setResults] = useState<ClientResult[]>([]);
  const [loading, setLoading] = useState(false);
  const [addingId, setAddingId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [searched, setSearched] = useState(false);

  /** Поиск клиентов */
  const handleSearch = useCallback(async () => {
    const query = searchQuery.trim();
    if (!query) return;

    setLoading(true);
    setError(null);
    setSearched(true);
    try {
      const response = await clientsApi.getList({
        search: query,
        per_page: 20,
        page: 1,
      });
      const clients = response.data?.data?.clients || [];
      setResults(clients.map((client: Client) => ({
        id: client.id,
        name: client.name,
        phone: client.phone,
        client_type: client.client_type,
      })));
    } catch {
      setError('Ошибка поиска');
      setResults([]);
    } finally {
      setLoading(false);
    }
  }, [searchQuery]);

  /** Привязать объявление к клиенту */
  const handleAdd = async (clientId: number) => {
    setAddingId(clientId);
    try {
      await clientsApi.addListing(clientId, listingId);
      onAdded();
    } catch (err: any) {
      const message = err.response?.data?.message || 'Ошибка привязки';
      alert(message);
    } finally {
      setAddingId(null);
    }
  };

  return (
    <div className="modal-overlay">
      <div className="client-picker-modal">
        <div className="modal-header">
          <h2>Привязать к клиенту</h2>
          <button className="close-btn" onClick={onClose}>
            <span className="material-icons">close</span>
          </button>
        </div>

        <div className="client-picker-content">
          {/* Поиск */}
          <div className="client-search-row">
            <input
              type="text"
              className="client-search-input"
              placeholder="Поиск по имени или телефону..."
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
          {error && <div className="client-picker-error">{error}</div>}

          {results.length > 0 && (
            <div className="client-picker-results">
              {results.map(client => (
                <div key={client.id} className="client-picker-item">
                  <div className="client-picker-info">
                    <div className="client-picker-name">{client.name}</div>
                    <div className="client-picker-details">
                      <span className={`client-picker-type type-${client.client_type}`}>
                        {CLIENT_TYPE_LABELS[client.client_type]}
                      </span>
                      {client.phone && <span className="client-picker-phone">{client.phone}</span>}
                    </div>
                  </div>
                  <button
                    className="btn-primary btn-sm"
                    onClick={() => handleAdd(client.id)}
                    disabled={addingId === client.id}
                  >
                    {addingId === client.id ? '...' : 'Привязать'}
                  </button>
                </div>
              ))}
            </div>
          )}

          {searched && results.length === 0 && !loading && !error && (
            <div className="client-picker-empty">Клиенты не найдены</div>
          )}
        </div>
      </div>
    </div>
  );
}

export default ClientPicker;
