import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { ClientType } from '../types/client';

/** Режим отображения: список или kanban */
type ViewMode = 'list' | 'pipeline';

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
      }),
    }
  )
);
