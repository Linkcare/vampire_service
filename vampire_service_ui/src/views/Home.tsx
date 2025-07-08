import PageHeader from "../components/PageBuilder/PageHeader";
import SectionBlock from "../components/PageBuilder/SectionBlock";
import ShipmentsTable from "../components/ShipmentsTable";
import ProtectedRoute from "../components/ProtectedRoute";
import { useSession } from "../components/SessionContext";
import { ActionBar, Button } from "@chakra-ui/react";
import { useNavigate } from "react-router-dom";
import { createShipment } from "../services/VampireService/VampireService";
import { dateFormat } from "../helpers/Helpers";
import { LuPackagePlus } from "react-icons/lu";

function Home() {
  const { session } = useSession();
  const navigate = useNavigate();

  const handleNewShipment = async () => {
    // Generate a reference from the current date in format YYYYMMDD
    const ref =
      `${session?.teamName}-` + dateFormat(new Date(), "yyyyMMddHHmmss");
    const newShipmentId = await createShipment(ref, session?.teamId || null);
    navigate(`/new/${newShipmentId}`);
  };
  return (
    <ProtectedRoute>
      <PageHeader showHomeLink={false}>Blood samples management</PageHeader>
      {session?.can("create_shipments") && (
        <ActionBar.Root open={true}>
          <ActionBar.Positioner>
            <ActionBar.Content>
              <Button variant="outline" size="2xl" onClick={handleNewShipment}>
                <LuPackagePlus />
                New shipment
              </Button>
            </ActionBar.Content>
          </ActionBar.Positioner>
        </ActionBar.Root>
      )}
      {session?.can("view_shipments") && (
        <SectionBlock>
          <ShipmentsTable />
        </SectionBlock>
      )}
    </ProtectedRoute>
  );
}

export default Home;
