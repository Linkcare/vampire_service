import "../Styles.css";
import { Box, HStack, Span } from "@chakra-ui/react";
import SectionBlock from "./PageBuilder/SectionBlock";
import ProtectedRoute from "./ProtectedRoute";
import {
  Shipment,
  ShipmentReceptionStatus,
  ShipmentStatus,
} from "../types/ShipmentTypes";
import { formatAsString } from "../helpers/Helpers";

const ReceptionColor = (shipment: Shipment) => {
  switch (shipment.receptionStatusId) {
    case ShipmentReceptionStatus.ALL_GOOD:
      return "green.500";
    case ShipmentReceptionStatus.PARTIALLY_BAD:
      return "orange.500";
    case ShipmentReceptionStatus.ALL_BAD:
      return "red.500";
    default:
      return "gray.500"; // Default color for unknown status
  }
};

function ShipmentReceptionDetails({ shipment }: { shipment: Shipment }) {
  return (
    <ProtectedRoute>
      <SectionBlock title="Reception details">
        <HStack justify="stretch" gap={30}>
          <Box>
            <strong>Reception Date:</strong>{" "}
            {shipment.receptionDate
              ? formatAsString(shipment.receptionDate)
              : "Not received yet"}
          </Box>
          {shipment.statusId === ShipmentStatus.RECEIVED && (
            <>
              <Box>
                <strong>Received by:</strong> {shipment.receiver}
              </Box>
              <Box>
                <strong>Status on reception:</strong>{" "}
                <Span color={ReceptionColor(shipment)}>
                  {shipment.getReceptionStatusStr()}
                </Span>
              </Box>
            </>
          )}
        </HStack>
      </SectionBlock>
    </ProtectedRoute>
  );
}

export default ShipmentReceptionDetails;
