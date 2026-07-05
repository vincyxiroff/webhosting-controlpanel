import { create } from "zustand";

type SessionState = {
  accessToken: string | null;
  tenantId: string | null;
  setSession: (accessToken: string, tenantId: string) => void;
  clear: () => void;
};

export const useSession = create<SessionState>((set) => ({
  accessToken: null,
  tenantId: null,
  setSession: (accessToken, tenantId) => set({ accessToken, tenantId }),
  clear: () => set({ accessToken: null, tenantId: null })
}));

