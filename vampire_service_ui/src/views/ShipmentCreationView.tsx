import "../Styles.css";
import PageHeader from "../components/PageBuilder/PageHeader";
import {
  Box,
  VStack,
  HStack,
  Input,
  ActionBar,
  Button,
  Portal,
  Dialog,
  Separator,
} from "@chakra-ui/react";
import { Shipment, Aliquot } from "../types/ShipmentTypes";
import { useEffect, useRef, useState } from "react";
import SectionBlock from "../components/PageBuilder/SectionBlock";
import ProtectedRoute from "../components/ProtectedRoute";
import { TbPackageImport, TbTrashX } from "react-icons/tb";
import DataTable, {
  ColumnProps,
  FetchResult,
  RowActionResult,
} from "../components/DataTable/DataTable";
import SelectLab from "../components/SelectLab";
import { useSession } from "../components/SessionContext";
import LabelInputPair from "../components/PageBuilder/LabelInputPair";
import { BsFillSendSlashFill, BsSendCheckFill, BsTrash } from "react-icons/bs";
import { useNavigate, useParams } from "react-router-dom";
import SpinnerOverlay from "../components/SpinnerOverlay";
import * as VampireService from "../services/VampireService/VampireService";
import ErrorAlert from "../components/ErrorAlert";
import AliquotStatus from "../types/AliquotStatus";
import {
  ButtonColors,
  FormFieldsColors,
} from "../components/PageBuilder/Styles";

const shippedAliquotColumns: ColumnProps<Aliquot>[] = [
  { header: "ID", accessor: "id", filterable: false },
  { header: "Patient", accessor: "patientRef", filterable: false },
  { header: "Type", accessor: "type", filterable: false },
];
const availableAliquotcolumns: ColumnProps<Aliquot>[] = [
  { header: "ID", accessor: "id", filterable: false },
  { header: "Patient", accessor: "patientRef", filterable: true },
  { header: "Type", accessor: "type", filterable: true },
  { header: "Location", accessor: "location", filterable: false },
];

/**
 * Component NewShipment
 */
function ShipmentCreationView() {
  const { id } = useParams();
  const shipmentId = Number(id);

  const { session } = useSession();
  const [selectedAliquots, setSelectedAliquots] = useState<Aliquot[]>([]);
  const [extractionId, setExtractionId] = useState("");
  // Parameters for the shipment
  const [shipmentRef, setShipmentRef] = useState("");
  const [fromLab, setFromLab] = useState<string | null>(null);
  const [destLabId, setDestLabId] = useState<string | null>(null);
  const shipment = new Shipment();
  const [isReady, setIsReady] = useState<boolean>(false);
  const [sending, setSending] = useState<boolean>(false);
  const [aliquotNotFound, setAliquotNotFound] = useState<boolean>(false);
  const [forceRefresh, setForceRefresh] = useState(0);
  const [operationError, setOperationError] = useState<string | null>(null);
  const navigate = useNavigate();
  const isFirstRender = useRef(true);
  const aliquotIdInputRef = useRef<HTMLInputElement>(null);

  // Load the shipment data when the component mounts
  useEffect(() => {
    const loadShipment = async () => {
      // REST call to fetch shipments
      let response: Shipment | null = null;
      try {
        response = await VampireService.loadShipmentDetails(shipmentId);
        setShipmentRef(response.ref || "");
        setFromLab(response.sentFrom || null);
        setDestLabId(response.sentToId ? response.sentToId.toString() : null);
        setSelectedAliquots(response.aliquots || []);
      } catch (error) {
        return;
      }
    };
    loadShipment();
    aliquotIdInputRef.current?.focus();
  }, []);

  useEffect(() => {
    /* Update the shipment object whenever the shipmentRef or destLabId change.
     * It is only necessary to perform an update when the shipmentRef or destLabId change, because the aliquots are updated automatically when included or excluded from the shipment.     * This ensures that the shipment object always has the latest values.
     * We use a timeout to avoid updating the shipment object too frequently while the user is typing.
     */
    const delayedHandler = setTimeout(() => {
      updateShipment();
    }, 1000);

    return () => {
      clearTimeout(delayedHandler);
    };
  }, [shipmentRef, destLabId]);

  useEffect(() => {
    // Check if the shipment is ready to be sent
    const allow =
      shipmentRef.trim() !== "" &&
      destLabId !== null &&
      selectedAliquots.length > 0;
    setIsReady(allow);
  }, [shipmentRef, destLabId, selectedAliquots]);

  const loadAvailableAliquots = async ({
    page,
    pageSize,
    filters,
  }: {
    page: number;
    pageSize: number;
    filters: Partial<Record<keyof Aliquot, string>>;
  }): Promise<FetchResult<Aliquot>> => {
    const response = await VampireService.loadShippableAliquots(
      session?.teamId || null,
      page,
      pageSize,
      filters
    );

    // Simulated response data
    // Simulate a delay
    // await new Promise((resolve) => setTimeout(resolve, 1000));
    // const aliquots = availableAliquots();
    const retValue: FetchResult<Aliquot> = {
      data: response.rows,
      totalPages: Math.ceil(response.total_count / pageSize),
    };

    return retValue;
  };

  const updateShipment = async () => {
    if (isFirstRender.current) {
      // Skip the first render to avoid updating the shipment object with empty values
      isFirstRender.current = false;
      return;
    }

    shipment.id = shipmentId;
    shipment.ref = shipmentRef;
    shipment.sentFromId = session?.teamId || null;
    shipment.sentToId = destLabId ? Number(destLabId) : null;
    setOperationError("");
    try {
      await VampireService.updateShipment(shipment);
    } catch (error) {
      if (error instanceof Error) {
        setOperationError(error.message);
      } else {
        setOperationError(String(error));
      }
    }
  };

  /*
   * Function to extract an aliquot from the list of available aliquots and include it in the shipment.
   * If the aliquot is found, it will be removed from the list and the input field will be cleared.
   */
  const addAliquotToShipment = async (
    row: string | Aliquot
  ): Promise<RowActionResult> => {
    let selected: Aliquot | null;
    let aliquotId: string | undefined;

    setAliquotNotFound(false);
    if (typeof row === "string") {
      aliquotId = row;
      try {
        selected = await VampireService.findAliquot(
          aliquotId || "",
          session?.teamId || null,
          AliquotStatus.AVAILABLE
        );
      } catch (error) {
        setAliquotNotFound(true);
        return RowActionResult.NONE;
      }
    } else {
      selected = row; // If row is already an Aliquot object, use it directly
    }

    if (selected) {
      await VampireService.shipmentAddAliquot(shipmentId, selected.id || "");
      setSelectedAliquots((prev) => [...prev, selected]);
      setExtractionId(""); // Clear the input field after removal
      setForceRefresh((prev) => prev + 1); // Toggle forceRefresh to trigger DataTable refresh
    } else {
      setAliquotNotFound(true);
      setExtractionId(aliquotId || ""); // Update the <input> with the current value
    }
    return RowActionResult.NONE;
  };

  /*
   * Function to extract an aliquot from the list of available aliquots and include it in the shipment.
   * If the aliquot is found, it will be removed from the list and the input field will be cleared.
   */
  const removeAliquotFromShipment = async (
    row: string | Aliquot
  ): Promise<RowActionResult> => {
    const aliquotId = typeof row === "string" ? row : row.id;
    const selected = selectedAliquots?.find((a) => a.id === aliquotId);

    if (selected && selectedAliquots) {
      await VampireService.shipmentRemoveliquot(shipmentId, selected.id || "");
      const newList = selectedAliquots.filter((a) => a.id !== aliquotId);
      setSelectedAliquots(newList);
      //      setAliquots((prev) => [...(prev || []), selected]);
      setForceRefresh((prev) => prev + 1); // Toggle forceRefresh to trigger DataTable refresh
    }

    return RowActionResult.NONE;
  };

  /**
   * Send the new shipment to the server.
   * If the shipment is successfully sent, navigate to the home page, otherwise display an error message.
   */
  const send = async () => {
    shipment.id = shipmentId;
    shipment.ref = shipmentRef;
    shipment.sentFromId = session?.teamId || null;
    shipment.senderId = session?.userId || null;
    shipment.sentToId = destLabId ? Number(destLabId) : null;

    setOperationError(null);
    setSending(true);
    try {
      await VampireService.sendShipment(shipment);
      navigate("/");
    } catch (error) {
      if (error instanceof Error) {
        setOperationError(error.message);
      } else {
        setOperationError(String(error));
      }
    } finally {
      setSending(false);
    }
  };

  /**
   * Send the new shipment to the server.
   * If the shipment is successfully sent, navigate to the home page, otherwise display an error message.
   */
  const remove = async () => {
    setOperationError(null);
    try {
      await VampireService.deleteShipment(shipmentId);
      navigate("/");
    } catch (error) {
      if (error instanceof Error) {
        setOperationError(error.message);
      } else {
        setOperationError(String(error));
      }
    }
  };

  let availableListActions = [
    {
      action: addAliquotToShipment,
      title: "",
      icon: <TbPackageImport />,
      tooltip: "Add to shipment",
    },
  ];

  let includedListActions = [
    {
      action: removeAliquotFromShipment,
      title: "",
      icon: <TbTrashX />,
      tooltip: "Add to shipment",
    },
  ];

  return (
    <ProtectedRoute>
      <PageHeader>New aliquots shipment</PageHeader>
      <Box p={4} borderWidth={1} borderRadius="md" mb={4}>
        <VStack gap={4} align="stretch">
          {operationError && <ErrorAlert errorMessage={operationError} />}

          <SectionBlock title="Shipment details">
            <form>
              <VStack gap={2} align="stretch">
                <HStack justify="stretch" gap={6}>
                  <LabelInputPair label="From:">{fromLab}</LabelInputPair>{" "}
                  <LabelInputPair label="To:">
                    <SelectLab
                      excludeLocation={session?.teamId?.toString()}
                      bg={
                        destLabId
                          ? FormFieldsColors.Default
                          : FormFieldsColors.Mandatory
                      }
                      value={`${destLabId}`}
                      placeholder="Select destination lab"
                      handleChange={setDestLabId}
                    />
                  </LabelInputPair>{" "}
                  <LabelInputPair label="Shipment ID:">
                    <Input
                      value={shipmentRef}
                      bg={
                        shipmentRef
                          ? FormFieldsColors.Default
                          : FormFieldsColors.Mandatory
                      }
                      onChange={(e) => {
                        setShipmentRef(e.target.value);
                      }}
                      width="30ch"
                    />
                  </LabelInputPair>
                </HStack>
              </VStack>
            </form>
          </SectionBlock>
          <Separator />

          {/* Input to type aliquot Ids to include in the shipment */}
          <SectionBlock>
            <HStack justify="stretch" gap={4} align="stretch">
              <Box>
                <LabelInputPair
                  label=""
                  invalid={aliquotNotFound}
                  errorMessage={`Not found`}
                >
                  <Input
                    ref={aliquotIdInputRef}
                    placeholder="Type ID of the aliquot to include"
                    variant="subtle"
                    value={extractionId}
                    onChange={(e) => {
                      setExtractionId(e.target.value);
                      setAliquotNotFound(false);
                    }}
                    onKeyUp={(e) => {
                      if (e.key == "Enter") {
                        addAliquotToShipment(e.currentTarget.value);
                      }
                    }}
                    width="50ch"
                  />
                </LabelInputPair>
              </Box>
            </HStack>
          </SectionBlock>

          {/* Table with the list of selected aliquots */}
          <SectionBlock>
            <DataTable<Aliquot>
              columns={shippedAliquotColumns}
              maxHeight="25vw"
              pageSize={2}
              initialData={selectedAliquots}
              rowActions={includedListActions}
              title={`Aliquots included in the shipment: ${selectedAliquots.length}`}
            ></DataTable>
          </SectionBlock>

          <SectionBlock title="Available aliquots">
            {/* Table with the list of available aliquots */}
            <DataTable<Aliquot>
              columns={availableAliquotcolumns}
              pageSize={10}
              fetchData={loadAvailableAliquots}
              rowActions={availableListActions}
              forceRefresh={forceRefresh}
            ></DataTable>
          </SectionBlock>
        </VStack>
      </Box>

      {/*
       *ACTION BAR: Button for sending the new shipment
       */}
      {session?.can("create_shipments") && (
        <ActionBar.Root open={true}>
          <ActionBar.Positioner>
            <ActionBar.Content>
              <Dialog.Root placement="center">
                <Dialog.Trigger asChild>
                  <Button
                    disabled={!isReady}
                    variant="surface"
                    colorPalette={isReady ? "green" : "red"}
                    size="sm"
                  >
                    {isReady ? <BsSendCheckFill /> : <BsFillSendSlashFill />}
                  </Button>
                </Dialog.Trigger>

                <Portal>
                  <Dialog.Backdrop />
                  <Dialog.Positioner>
                    <Dialog.Content>
                      <Dialog.Header>
                        <Dialog.Title>Send shipment</Dialog.Title>
                      </Dialog.Header>
                      <Dialog.Body>
                        <Dialog.Description>
                          Do you want to send{" "}
                          <strong>{selectedAliquots.length}</strong> aliquots in
                          the shipment with ID <strong>{shipmentRef}</strong>?
                        </Dialog.Description>
                      </Dialog.Body>
                      <Dialog.Footer>
                        <Dialog.ActionTrigger asChild>
                          <Button variant="outline">Cancel</Button>
                        </Dialog.ActionTrigger>
                        <Dialog.ActionTrigger asChild>
                          <Button
                            colorPalette="green"
                            onClick={() => {
                              send();
                            }}
                          >
                            Send
                          </Button>
                        </Dialog.ActionTrigger>
                      </Dialog.Footer>
                    </Dialog.Content>
                  </Dialog.Positioner>
                </Portal>
              </Dialog.Root>

              <Dialog.Root placement="center">
                <Dialog.Trigger asChild>
                  <Button variant="surface" size="sm">
                    {<BsTrash />}
                  </Button>
                </Dialog.Trigger>

                <Portal>
                  <Dialog.Backdrop />
                  <Dialog.Positioner>
                    <Dialog.Content>
                      <Dialog.Header>
                        <Dialog.Title>Send shipment</Dialog.Title>
                      </Dialog.Header>
                      <Dialog.Body>
                        <Dialog.Description>
                          Do you want to delete the shipment?
                        </Dialog.Description>
                      </Dialog.Body>
                      <Dialog.Footer>
                        <Dialog.ActionTrigger asChild>
                          <Button variant="outline">Cancel</Button>
                        </Dialog.ActionTrigger>
                        <Dialog.ActionTrigger asChild>
                          <Button
                            colorPalette={ButtonColors.Critical}
                            onClick={() => {
                              remove();
                            }}
                          >
                            Delete
                          </Button>
                        </Dialog.ActionTrigger>
                      </Dialog.Footer>
                    </Dialog.Content>
                  </Dialog.Positioner>
                </Portal>
              </Dialog.Root>
            </ActionBar.Content>
          </ActionBar.Positioner>
        </ActionBar.Root>
      )}

      {sending && <SpinnerOverlay />}
    </ProtectedRoute>
  );
}

export default ShipmentCreationView;
