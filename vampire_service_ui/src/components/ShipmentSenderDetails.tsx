import "../Styles.css";
import { Box, HStack } from "@chakra-ui/react";
import SectionBlock from "./PageBuilder/SectionBlock";
import ProtectedRoute from "./ProtectedRoute";
import { Shipment } from "../types/ShipmentTypes";
import { dateFormat } from "../helpers/Helpers";

function ShipmentSenderDetails({ shipment }: { shipment: Shipment }) {
  return (
    <ProtectedRoute>
      <SectionBlock title="Shipment details">
        <Box>
          <strong>Status:</strong> {shipment.getStatusStr()}
        </Box>

        <HStack justify="stretch" gap={30}>
          <Box>
            <strong>Sent From:</strong> {shipment.sentFrom}
          </Box>
          <Box>
            <strong>To:</strong> {shipment.sentTo}
          </Box>
          <Box>
            <strong>Date:</strong> {dateFormat(shipment.sendDate)}
          </Box>
          <Box>
            <strong>Sender:</strong> {shipment.sender}
          </Box>
        </HStack>
      </SectionBlock>
    </ProtectedRoute>
  );
}

export default ShipmentSenderDetails;
