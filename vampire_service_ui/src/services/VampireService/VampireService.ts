import axios, { AxiosInstance } from "axios";
import { Aliquot, Shipment, ShipmentLocation } from "../../types/ShipmentTypes";

const vampireService: AxiosInstance = axios.create({
  baseURL: (window as any).__APP_CONFIG__?.shipment_service_endpoint,
  headers: {
    "Content-Type": "application/json",
  },
  timeout: 10000, // Opcional: tiempo máximo de espera para una petición
});

let token: string | null = null;

const initializeVampireService = (sessionToken: string) => {
  token = sessionToken;
};

vampireService.interceptors.request.use(
  (config) => {
    if (token) {
      config.headers["X-API-token"] = token;
    }
    return config;
  },
  (error) => Promise.reject(error)
);

interface ApiResponse {
  result: any | null;
  error: string | null;
}

const invokeService = async <T extends Object>(
  functionName: string,
  data?: any
) => {
  data = data || {};

  const response = await vampireService.post<ApiResponse>(functionName, data);
  if (response.status !== 200) {
    throw new Error(`Error: ${response.status} - ${response.statusText}`);
  }
  const apiResponse: ApiResponse = response.data;
  if (!apiResponse) {
    throw new Error("No response from the service");
  }

  if (
    "error" in apiResponse &&
    apiResponse.error !== null &&
    apiResponse.error !== undefined &&
    apiResponse.error !== ""
  ) {
    throw new Error(`${apiResponse.error}`);
  }
  return apiResponse.result as T;
};

/* ************************************************************************* */
/* ************************************************************************* */
/* ************************************************************************* */
export interface PaginatedResponse<T> {
  rows: T[];
  total_count: number;
  error?: string | null;
}

/**
 * Initializes the Vampire Service.
 * This function should be called only once when setting up the Vampire Service
 * @returns Logs of the service initialization procedure
 */
const deployService = async (): Promise<string[]> => {
  const response = await invokeService<string[]>("deploy_service");
  return response;
};

const shipmentLocations = async (): Promise<ShipmentLocation[]> => {
  const response = await invokeService<ShipmentLocation[]>(
    "shipment_locations"
  );
  return response.map((location) => ShipmentLocation.fromJSON(location));
};
/**
 *
 * @returns A list of shipments
 * @throws Error if the service call fails
 */
const loadShipmentList = async (
  page: number,
  pageSize: number,
  filters?: Partial<Record<keyof Shipment, string>>
): Promise<PaginatedResponse<Shipment>> => {
  const params = {
    page: page,
    pageSize: pageSize,
    filters: filters,
  };
  const response = await invokeService<PaginatedResponse<Shipment>>(
    "shipment_list",
    params
  );
  const shipments = response.rows.map((shipment) =>
    Shipment.fromJSON(shipment)
  );
  response.rows = shipments;
  return response;
};

/**
 *
 * @returns The details of a specific shipment
 * @throws Error if the service call fails
 */
const loadShipmentDetails = async (id: Number): Promise<Shipment> => {
  const params = {
    id: id,
  };
  const response = await invokeService<Shipment>("shipment_details", params);
  return Shipment.fromJSON(response);
};

/**
 *
 * @returns A list of shippable aliquots
 * @throws Error if the service call fails
 */
const loadShippableAliquots = async (
  locationId: number | null,
  page: number,
  pageSize: number,
  filters?: Partial<Record<keyof Aliquot, string>>
): Promise<PaginatedResponse<Aliquot>> => {
  const params = {
    locationId: locationId,
    page: page,
    pageSize: pageSize,
    filters: filters,
  };
  const response = await invokeService<PaginatedResponse<Aliquot>>(
    "shippable_aliquots",
    params
  );
  const aliquots = response
    ? response.rows.map((aliquot) => Aliquot.fromJSON(aliquot))
    : [];
  response.rows = aliquots;
  return response;
};

/**
 * Request to the server to find an aliquot by its ID and optional filters.
 * @param aliquotId The ID of the aliquot to find
 * @param locationId Optional location ID to filter the aliquot
 * @param statusId Optional status ID to filter the aliquot
 * @returns Aliquot
 * @throws Error if the service call fails
 */
const findAliquot = async (
  aliquotId: string,
  locationId: number | null,
  statusId: string | null
): Promise<Aliquot> => {
  const params = {
    aliquotId: aliquotId,
    locationId: locationId,
    statusId: statusId,
  };
  const response = await invokeService<Aliquot>("find_aliquot", params);
  return Aliquot.fromJSON(response);
};

/**
 * Creates a new shipment with the provided aliquots.
 * @param shipment The Shipment object containing the details of the shipment
 * @returns The ID of the created shipment or null if creation failed
 * @throws Error if the service call fails
 */
const createShipment = async (
  ref: string,
  from: number | null
): Promise<string | null> => {
  const params = {
    ref: ref,
    sentFromId: from,
  };

  const response = await invokeService<{ id: string }>(
    "shipment_create",
    params
  );

  return response.id;
};

export const shipmentAddAliquot = async (
  shipmentId: number,
  aliquotId: string
): Promise<void> => {
  const params = {
    shipmentId: shipmentId,
    aliquotId: aliquotId,
  };

  await invokeService<any>("shipment_add_aliquot", params);
};

export const shipmentRemoveliquot = async (
  shipmentId: number,
  aliquotId: string
): Promise<void> => {
  const params = {
    shipmentId: shipmentId,
    aliquotId: aliquotId,
  };

  await invokeService<any>("shipment_remove_aliquot", params);
};

/**
 * Indicate that the shipment has been performed
 * @param shipment The Shipment object containing the details of the shipment
 * @returns The ID of the created shipment
 * @throws Error if the service call fails
 */
const sendShipment = async (shipment: Shipment): Promise<string | null> => {
  const params = shipment.toJSON();

  const response = await invokeService<string>("shipment_send", params);

  return response;
};

/**
 * Starts the reception process of a shipment. This generally only involves changing the status of the shipment to "RECEIVING".
 * @param shipment The Shipment object containing the details of the shipment
 * @returns The ID of the updated shipment
 * @throws Error if the service call fails
 */
const startShipmentReception = async (
  shipment: Shipment
): Promise<string | null> => {
  const params = shipment.toJSON();

  const response = await invokeService<string>(
    "shipment_start_reception",
    params
  );

  return response;
};
/**
 * Acknowledges the reception of a shipment
 * @param shipment The Shipment object containing the details of the shipment
 * @returns The ID of the updated shipment
 * @throws Error if the service call fails
 */
const ackShipmentReception = async (
  shipment: Shipment
): Promise<string | null> => {
  const params = shipment.toJSON();

  const response = await invokeService<string>(
    "shipment_finish_reception",
    params
  );

  return response;
};

/**
 * Deletes a shipment)
 * @param id
 * @returns
 */
const deleteShipment = async (id: Number): Promise<string | null> => {
  const params = { id: id };

  const response = await invokeService<string>("shipment_delete", params);

  return response;
};

/**
 * Updates the information of an existing shipment
 * @param shipment The Shipment object containing the details of the shipment
 * @returns The ID of the shipment
 * @throws Error if the service call fails
 */
const updateShipment = async (shipment: Shipment): Promise<string | null> => {
  const params = shipment.toJSON();

  const response = await invokeService<string>("shipment_update", params);
  return response;
};

/**
 * Updates the information of an existing shipment
 * @param shipment The Shipment object containing the details of the shipment
 * @returns The ID of the shipment
 * @throws Error if the service call fails
 */
const markAliquotCondition = async (
  shipmentId: number,
  aliquotId: string,
  conditionId: string | null
): Promise<string | null> => {
  const params = {
    shipmentId: shipmentId,
    aliquotId: aliquotId,
    conditionId: conditionId,
  };

  const response = await invokeService<string>(
    "shipment_set_aliquot_condition",
    params
  );
  return response;
};

export {
  deployService,
  shipmentLocations,
  initializeVampireService,
  loadShipmentList,
  loadShipmentDetails,
  loadShippableAliquots,
  findAliquot,
  createShipment,
  sendShipment,
  deleteShipment,
  updateShipment,
  startShipmentReception,
  ackShipmentReception,
  markAliquotCondition,
};
