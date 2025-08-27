import axios from "axios";

export interface ApiResponse {
  result: any | null;
  ErrorMsg: string | null;
  ErrorCode: string | null;
}

const LinkcareApi = axios.create({
  baseURL: (window as any).__APP_CONFIG__?.lkapi_endpoint, // "https://prevampire-api.linkcareapp.com/rest_api",
  headers: {
    "Content-Type": "application/json",
  },
  timeout: 10000, // Opcional: tiempo máximo de espera para una petición
});

let token: string | null = null;

/**
 *
 * @param sessionToken Store the session token for subsequent API calls.
 */
export const fromStoredSession = (sessionToken: string) => {
  token = sessionToken;
};

/**
 * Invoke an API function that returns an special (non stardard) response structure.
 * @param functionName
 * @param data
 * @returns
 */
const specialInvokeApi = async <T extends Object>(
  functionName: string,
  data?: any
) => {
  const response = await LinkcareApi.post<T>(functionName, data);
  if (response.status !== 200) {
    throw new Error(`Error: ${response.status} - ${response.statusText}`);
  }
  const apiResponse: T = response.data;
  if (
    "ErrorCode" in apiResponse &&
    apiResponse.ErrorCode !== null &&
    apiResponse.ErrorCode !== undefined &&
    apiResponse.ErrorCode !== ""
  ) {
    throw new Error(
      `${
        "ErrorMsg" in apiResponse ? apiResponse.ErrorMsg : apiResponse.ErrorCode
      }`
    );
  }
  return apiResponse;
};

/**
 * Invoke an API function that returns an stardard ApiResponse structure.
 * @param functionName
 * @param data
 * @returns T
 */
const invokeApi = async <T extends Object>(
  functionName: string,
  data?: any
) => {
  data = data || {};
  if (token) {
    data.session = token;
  }

  const response = await LinkcareApi.post<ApiResponse>(functionName, data);
  if (response.status !== 200) {
    throw new Error(`Error: ${response.status} - ${response.statusText}`);
  }
  const apiResponse: ApiResponse = response.data;
  if (
    apiResponse.ErrorCode !== null &&
    apiResponse.ErrorCode !== undefined &&
    apiResponse.ErrorCode !== ""
  ) {
    throw new Error(`${apiResponse.ErrorMsg || apiResponse.ErrorCode}`);
  }
  return apiResponse.result as T;
};

export default LinkcareApi;
export { specialInvokeApi, invokeApi };
