// SessionContext.tsx
import {
  createContext,
  useContext,
  useState,
  useEffect,
  ReactNode,
} from "react";
import { initializeVampireService } from "../services/VampireService/VampireService";
import { fromStoredSession } from "../services/LinkcareAPI/LinkcareApi";

export type SessionProps = {
  username: string; // Opcional, puede no estar presente
  userId: number;
  name: string;
  token: string;
  teamId: number | null;
  teamName: string;
  roleId: number | null;
};

export class Session {
  public token: string;
  public userId: number;
  public name: string;
  public teamId: number | null;
  public teamName: string;
  public roleId: number | null;

  constructor(params: SessionProps) {
    this.token = params.token;
    this.userId = params.userId;
    this.name = params.name;
    this.teamId = params.teamId;
    this.teamName = params.teamName;
    this.roleId = params.roleId;
  }

  can(action: string): boolean {
    if (action != "dummy") {
      return true;
    }

    return true; // Placeholder, siempre permite la acción
  }
}

type SessionContextType = {
  session: Session | null;
  setSession: (session: SessionProps | null) => void;
};

const SessionContext = createContext<SessionContextType | undefined>(undefined);

export const SessionProvider = ({ children }: { children: ReactNode }) => {
  const [session, setSessionState] = useState<Session | null>(null);

  // Cargar desde sessionStorage al iniciar
  useEffect(() => {
    const stored = sessionStorage.getItem("session");
    if (stored) {
      const storedSession = new Session(JSON.parse(stored));
      fromStoredSession(storedSession.token);
      initializeVampireService(storedSession.token);
      setSessionState(storedSession);
    }
  }, []);

  const setSession = (sessionProps: SessionProps | null) => {
    let session;

    if (sessionProps) {
      session = new Session(sessionProps);
      initializeVampireService(session.token);

      sessionStorage.setItem("session", JSON.stringify(sessionProps));
    } else {
      sessionStorage.removeItem("session");
      session = null;
    }

    setSessionState(session);
  };

  return (
    <SessionContext.Provider value={{ session, setSession }}>
      {children}
    </SessionContext.Provider>
  );
};

export const useSession = () => {
  const context = useContext(SessionContext);
  if (!context) {
    throw new Error("useSession must be used within a SessionProvider");
  }
  return context;
};
