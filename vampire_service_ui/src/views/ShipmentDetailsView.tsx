import "../Styles.css";
import { useNavigate, useParams } from "react-router-dom";
import PageHeader from "../components/PageBuilder/PageHeader";
import { Box, VStack, ActionBar, Button } from "@chakra-ui/react";
import { useEffect, useState } from "react";
import SectionBlock from "../components/PageBuilder/SectionBlock";
import ProtectedRoute from "../components/ProtectedRoute";
import { Aliquot, Shipment, ShipmentStatus } from "../types/ShipmentTypes";
import {
  loadShipmentDetails,
  startShipmentReception,
} from "../services/VampireService/VampireService";
import SpinnerOverlay from "../components/SpinnerOverlay";
import ErrorAlert from "../components/ErrorAlert";
import { useSession } from "../components/SessionContext";
import { LuPackageOpen } from "react-icons/lu";
import ShipmentSenderDetails from "../components/ShipmentSenderDetails";
import ShipmentReceptionDetails from "../components/ShipmentReceptionDetails";
import DataTable, { ColumnProps } from "../components/DataTable/DataTable";

function ShipmentDetailsView() {
  const { session } = useSession();
  const navigate = useNavigate();
  const { id } = useParams();
  const shipmentId = Number(id);
  const [shipmentRef, setShipmentRef] = useState<string | null>(null);
  const [shipment, setShipment] = useState<Shipment | null>(null);
  const [loaded, setLoaded] = useState<boolean>(false);
  const [fetchError, setFetchError] = useState<string | null>(null);

  // Load the shipment data when the component mounts
  useEffect(() => {
    const loadShipment = async () => {
      // REST call to fetch shipments
      let response: Shipment | null = null;
      try {
        response = await loadShipmentDetails(shipmentId);
        setFetchError(null);
      } catch (error) {
        setShipment(null);
        setLoaded(true);
        setFetchError(
          `Error loading shipment details: ${
            error instanceof Error ? error.message : "Unknown error"
          }`
        );
        return;
      }

      setShipment(response);
      setShipmentRef(response?.ref);
      setLoaded(true);
    };
    loadShipment();
  }, []);

  const startReceptionProcess = async () => {
    if (!shipment) {
      setFetchError("No shipment data available to start reception process.");
      return;
    }
    await startShipmentReception(shipment);
    navigate(`/receive/${shipmentId}`);
  };

  const columns: ColumnProps<Aliquot>[] = [
    { header: "ID", accessor: "id", filterable: true },
    { header: "Patient", accessor: "patientRef", filterable: true },
    { header: "Type", accessor: "type", filterable: true },
  ];

  if (shipment?.statusId === ShipmentStatus.RECEIVED) {
    columns.push({
      header: "Condition",
      accessor: "getConditionStr",
      filterable: true,
    });
  }

  return (
    <ProtectedRoute>
      <PageHeader>{`Shipment: ${shipmentRef}`}</PageHeader>
      {loaded && shipment ? (
        <>
          <Box p={4} borderWidth={1} borderRadius="md" mb={4}>
            <VStack gap={4} align="stretch">
              <ShipmentSenderDetails shipment={shipment} />
              <ShipmentReceptionDetails shipment={shipment} />
              <SectionBlock title="Shipment contents">
                <DataTable<Aliquot>
                  columns={columns}
                  pageSize={5}
                  initialData={shipment.aliquots}
                ></DataTable>
              </SectionBlock>
            </VStack>
          </Box>
          {session?.can("receive_shipments") &&
            shipment.sentToId == session.teamId &&
            shipment.statusId == ShipmentStatus.SENT && (
              <ActionBar.Root open={true}>
                <ActionBar.Positioner>
                  <ActionBar.Content>
                    <Button
                      variant="outline"
                      size="2xl"
                      onClick={startReceptionProcess}
                    >
                      <LuPackageOpen />
                      Start reception
                    </Button>
                  </ActionBar.Content>
                </ActionBar.Positioner>
              </ActionBar.Root>
            )}
        </>
      ) : fetchError ? (
        <ErrorAlert errorMessage={fetchError} />
      ) : (
        <SpinnerOverlay />
      )}
    </ProtectedRoute>
  );
}

export default ShipmentDetailsView;
