import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { Provider } from "./components/ui/provider";
import App from "./App.tsx";

/**
 * Checks if the application configuration is defined and contains necessary endpoints.
 * Throws an error if any required configuration is missing.
 * @throws {Error} If the configuration is not defined or missing required endpoints.
 */
function checkConfig() {
  if (!(window as any).__APP_CONFIG__) {
    console.error("Application configuration is not defined.");
    throw new Error("Application configuration is missing.");
  }
  if (!(window as any).__APP_CONFIG__.lkapi_endpoint) {
    console.error("Linkcare API endpoint is not defined in the configuration.");
    throw new Error("Linkcare API endpoint is missing in the configuration.");
  }
  if (!(window as any).__APP_CONFIG__.shipment_service_endpoint) {
    console.error(
      "Shipment service endpoint is not defined in the configuration."
    );
    throw new Error(
      "shipment_service_endpoint is missing in the configuration."
    );
  }
}

async function init() {
  checkConfig();

  createRoot(document.getElementById("root")!).render(
    <StrictMode>
      <Provider>
        <App />
      </Provider>
    </StrictMode>
  );
}

init();
