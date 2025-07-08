import { BrowserRouter, Routes, Route } from "react-router-dom";
import { SessionProvider } from "./components/SessionContext";
import Home from "./views/Home";
import ShipmentDetailsView from "./views/ShipmentDetailsView";
import ShipmentCreationView from "./views/ShipmentCreationView";
import DeployService from "./components/DeployService";
import ShipmentReceptionView from "./views/ShipmentReceptionView";

function App() {
  let view;

  // Ensure the user is logged in
  view = (
    <SessionProvider>
      <BrowserRouter>
        <Routes>
          <Route path="/" element={<Home />} />
          <Route path="/shipment/:id" element={<ShipmentDetailsView />} />
          <Route path="/new/:id" element={<ShipmentCreationView />} />
          <Route path="/receive/:id" element={<ShipmentReceptionView />} />
          <Route path="/deploy" element={<DeployService />} />
        </Routes>
      </BrowserRouter>
    </SessionProvider>
  );
  return view;
}

export default App;
