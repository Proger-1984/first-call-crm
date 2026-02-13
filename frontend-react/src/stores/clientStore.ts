import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { ClientType } from '../types/client';

/** Режим отображения: список или kanban */
type ViewMode = 'list' | 'pipeline';

/** Поля для сортировки */
type SortField = 'created_at' | 'name' | 'last_contact_at' | 'next_contact_at' | 'budget_max';

interface ClientState {
  /** Режим отображения */
  viewMode: ViewMode;
  setViewMode: (mode: ViewMode) => void;

  /** Фильтры */
  searchQuery: string;
  setSearchQuery: (query: string) => void;

  selectedType: ClientType | '';
  setSelectedType: (type: ClientType | '') => void;

  selectedStageId: number | null;
  setSelectedStageId: (id: number | null) => void;

  showArchived: boolean;
  setShowArchived: (show: boolean) => void;

  /** Сортировка */
  sortField: SortField;
  setSortField: (field: SortField) => void;

  sortOrder: 'asc' | 'desc';
  setSortOrder: (order: 'asc' | 'desc') => void;

  /** Записей на странице */
  perPage: number;
  setPerPage: (perPage: number) => void;

  /** Сброс фильтров */
  resetFilters: () => void;
}

export const useClientStore = create<ClientState>()(
  persist(
    (set) => ({
      viewMode: 'list',
      setViewMode: (viewMode) => set({ viewMode }),

      searchQuery: '',
      setSearchQuery: (searchQuery) => set({ searchQuery }),

      selectedType: '',
      setSelectedType: (selectedType) => set({ selectedType }),

      selectedStageId: null,
      setSelectedStageId: (selectedStageId) => set({ selectedStageId }),

      showArchived: false,
      setShowArchived: (showArchived) => set({ showArchived }),

      sortField: 'created_at',
      setSortField: (sortField) => set({ sortField }),

      sortOrder: 'desc',
      setSortOrder: (sortOrder) => set({ sortOrder }),

      perPage: 20,
      setPerPage: (perPage) => set({ perPage }),

      resetFilters: () => set({
        searchQuery: '',
        selectedType: '',
        selectedStageId: null,
        showArchived: false,
      }),
    }),
    {
      name: 'client-storage',
      partialize: (state) => ({
        viewMode: state.viewMode,
        perPage: state.perPage,
        sortField: state.sortField,
        sortOrder: state.sortOrder,
      }),
    }
  )
);
