import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { DealType } from '../types/property';

/** Режим отображения: список или kanban */
type ViewMode = 'list' | 'pipeline';

/** Поля для сортировки объектов */
type SortField = 'created_at' | 'price' | 'address' | 'deal_type' | 'owner_name' | 'stage';

interface PropertyState {
  /** Режим отображения */
  viewMode: ViewMode;
  setViewMode: (mode: ViewMode) => void;

  /** Фильтры */
  searchQuery: string;
  setSearchQuery: (query: string) => void;

  selectedDealType: DealType | '';
  setSelectedDealType: (type: DealType | '') => void;

  /** Множественный выбор стадий (баг #3 — фикс) */
  selectedStageIds: number[];
  setSelectedStageIds: (ids: number[]) => void;
  toggleStageId: (id: number) => void;

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

export const usePropertyStore = create<PropertyState>()(
  persist(
    (set, get) => ({
      viewMode: 'list',
      setViewMode: (viewMode) => set({ viewMode }),

      searchQuery: '',
      setSearchQuery: (searchQuery) => set({ searchQuery }),

      selectedDealType: '',
      setSelectedDealType: (selectedDealType) => set({ selectedDealType }),

      selectedStageIds: [],
      setSelectedStageIds: (selectedStageIds) => set({ selectedStageIds }),
      toggleStageId: (id) => {
        const current = get().selectedStageIds;
        const next = current.includes(id)
          ? current.filter((stageId) => stageId !== id)
          : [...current, id];
        set({ selectedStageIds: next });
      },

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
        selectedDealType: '',
        selectedStageIds: [],
        showArchived: false,
      }),
    }),
    {
      name: 'property-storage',
      partialize: (state) => ({
        viewMode: state.viewMode,
        perPage: state.perPage,
        sortField: state.sortField,
        sortOrder: state.sortOrder,
      }),
    }
  )
);
