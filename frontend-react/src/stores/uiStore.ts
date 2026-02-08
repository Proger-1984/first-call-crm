import { create } from 'zustand';
import { persist } from 'zustand/middleware';

interface UIState {
  sidebarCollapsed: boolean;
  soundEnabled: boolean;
  toggleSidebar: () => void;
  setSidebarCollapsed: (collapsed: boolean) => void;
  toggleSound: () => void;
  setSoundEnabled: (enabled: boolean) => void;
  playNotificationSound: () => void;
}

// Функция воспроизведения звука через Web Audio API
const playBeep = () => {
  try {
    const audioContext = new (window.AudioContext || (window as any).webkitAudioContext)();
    const oscillator = audioContext.createOscillator();
    const gainNode = audioContext.createGain();
    
    oscillator.connect(gainNode);
    gainNode.connect(audioContext.destination);
    
    // Настройки звука "дзынь"
    oscillator.frequency.value = 880; // Нота A5
    oscillator.type = 'sine';
    
    // Плавное затухание
    gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
    gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.3);
    
    oscillator.start(audioContext.currentTime);
    oscillator.stop(audioContext.currentTime + 0.3);
    
    // Очистка
    setTimeout(() => {
      audioContext.close();
    }, 500);
  } catch (error) {
    console.warn('Не удалось воспроизвести звук:', error);
  }
};

export const useUIStore = create<UIState>()(
  persist(
    (set, get) => ({
      sidebarCollapsed: false,
      soundEnabled: true, // По умолчанию звук включён
      
      toggleSidebar: () => set((state) => ({ 
        sidebarCollapsed: !state.sidebarCollapsed 
      })),
      
      setSidebarCollapsed: (sidebarCollapsed) => set({ sidebarCollapsed }),
      
      toggleSound: () => set((state) => ({ 
        soundEnabled: !state.soundEnabled 
      })),
      
      setSoundEnabled: (soundEnabled) => set({ soundEnabled }),
      
      playNotificationSound: () => {
        if (get().soundEnabled) {
          playBeep();
        }
      },
    }),
    {
      name: 'ui-storage',
    }
  )
);
