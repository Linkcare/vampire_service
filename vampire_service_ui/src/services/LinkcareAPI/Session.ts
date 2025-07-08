import { fromStoredSession, specialInvokeApi } from "./LinkcareApi";
import { UAParser } from "ua-parser-js";

interface ApiSession {
  token: string;
  user: number;
  language: string;
  role: number | null | undefined;
  team: number | null | undefined;
  team_code: string | null | undefined;
  name: string | null | undefined;
  WS: string | null | undefined;
  professional: number | null | undefined;
  case: number | null | undefined;
  associate: number | null | undefined;
  timezone: string;
  image: string | null | undefined;
  ErrorMsg: string | null;
  ErrorCode: string | null;
}

/* ****************************************
 * session_init()
 * ****************************************/
export const session_init = async (
  user: string,
  password: string
): Promise<ApiSession> => {
  interface SessionExtraProps {
    ws_endpoint?: string | null;
    shared_key?: string | null;
    push_token?: string | null;
    country_code?: string | null;
    company?: string | null;
    client?: {
      os?: string | null;
      os_version?: string | null;
      browser?: string | null;
      browser_version?: string | null;
      app_name?: string | null;
    };
  }
  interface SessionInitParams {
    user: string;
    password: string;
    IP?: string;
    host?: string;
    language: string;
    APIVersion: string;
    current_date: string;
    extra?: SessionExtraProps;
  }
  const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

  // Name of the operating system (Windows, macOS, Linux, etc.)
  const parser = new (UAParser as any)(); //
  const hostInfo = parser.getResult();

  const fnParams: SessionInitParams = {
    user: user,
    password: password,
    current_date: timezone,
    APIVersion: "2.8.1",
    language: navigator.language || "en-US",
    extra: {
      client: {
        os: hostInfo.os.name || null,
        os_version: hostInfo.os.version || null,
        browser: hostInfo.browser.name || null,
        browser_version: hostInfo.browser.version || null,
        app_name: "Vampire service",
      },
    },
  };

  try {
    const session = await specialInvokeApi<ApiSession>(
      "session_init",
      fnParams
    );

    fromStoredSession(session.token);
    session.user = Number(session.user);
    session.role = session.role ? Number(session.role) : null;
    session.team = session.team ? Number(session.team) : null;
    session.professional = session.professional
      ? Number(session.professional)
      : null;
    session.case = session.case ? Number(session.case) : null;
    session.associate = session.associate ? Number(session.associate) : null;
    return session;
  } catch (error) {
    throw error;
  }
};
