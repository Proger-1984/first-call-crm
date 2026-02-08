import './Pagination.css';

interface PaginationProps {
  page: number;
  totalPages: number;
  perPage: number;
  total: number;
  onPageChange: (page: number) => void;
  onPerPageChange: (perPage: number) => void;
  perPageOptions?: number[];
}

export function Pagination({
  page,
  totalPages,
  perPage,
  total,
  onPageChange,
  onPerPageChange,
  perPageOptions = [10, 20, 50, 100],
}: PaginationProps) {
  // Генерируем номера страниц для отображения
  const getPageNumbers = () => {
    const pages: (number | string)[] = [];
    const maxVisible = 5;
    
    if (totalPages <= maxVisible + 2) {
      // Показываем все страницы
      for (let i = 1; i <= totalPages; i++) {
        pages.push(i);
      }
    } else {
      // Показываем с многоточием
      pages.push(1);
      
      if (page > 3) {
        pages.push('...');
      }
      
      const start = Math.max(2, page - 1);
      const end = Math.min(totalPages - 1, page + 1);
      
      for (let i = start; i <= end; i++) {
        pages.push(i);
      }
      
      if (page < totalPages - 2) {
        pages.push('...');
      }
      
      pages.push(totalPages);
    }
    
    return pages;
  };

  const from = total === 0 ? 0 : (page - 1) * perPage + 1;
  const to = Math.min(page * perPage, total);

  return (
    <div className="pagination-container">
      <div className="pagination-left">
        <span className="results-info">
          {from}-{to} из {total}
        </span>
      </div>
      
      <div className="pagination-center">
        <div className="pagination">
          {/* Первая страница */}
          <button
            className={`page-item ${page === 1 ? 'disabled' : ''}`}
            onClick={() => onPageChange(1)}
            disabled={page === 1}
          >
            <span className="material-icons">first_page</span>
          </button>
          
          {/* Предыдущая */}
          <button
            className={`page-item ${page === 1 ? 'disabled' : ''}`}
            onClick={() => onPageChange(page - 1)}
            disabled={page === 1}
          >
            <span className="material-icons">chevron_left</span>
          </button>
          
          {/* Номера страниц */}
          {getPageNumbers().map((pageNum, index) => (
            pageNum === '...' ? (
              <span key={`ellipsis-${index}`} className="page-item ellipsis">...</span>
            ) : (
              <button
                key={pageNum}
                className={`page-item ${page === pageNum ? 'active' : ''}`}
                onClick={() => onPageChange(pageNum as number)}
              >
                {pageNum}
              </button>
            )
          ))}
          
          {/* Следующая */}
          <button
            className={`page-item ${page >= totalPages ? 'disabled' : ''}`}
            onClick={() => onPageChange(page + 1)}
            disabled={page >= totalPages}
          >
            <span className="material-icons">chevron_right</span>
          </button>
          
          {/* Последняя страница */}
          <button
            className={`page-item ${page >= totalPages ? 'disabled' : ''}`}
            onClick={() => onPageChange(totalPages)}
            disabled={page >= totalPages}
          >
            <span className="material-icons">last_page</span>
          </button>
        </div>
      </div>
      
      <div className="pagination-right">
        <div className="per-page-selector">
          <span>Строк:</span>
          <select
            value={perPage}
            onChange={(e) => onPerPageChange(Number(e.target.value))}
          >
            {perPageOptions.map((option) => (
              <option key={option} value={option}>
                {option}
              </option>
            ))}
          </select>
        </div>
      </div>
    </div>
  );
}
