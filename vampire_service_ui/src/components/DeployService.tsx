import { useEffect, useRef, useState } from "react";
import PageHeader from "./PageBuilder/PageHeader";
import ProtectedRoute from "./ProtectedRoute";
import * as VampireService from "../services/VampireService/VampireService";
import { Box, VStack } from "@chakra-ui/react";

function DeployService() {
  const [deployLogs, setDeployLogs] = useState<string[]>([]);
  const isFirstRender = useRef(true);

  useEffect(() => {
    const deploy = async () => {
      try {
        if (!isFirstRender.current) {
          return;
        }
        isFirstRender.current = false;
        setDeployLogs(await VampireService.deployService());
      } catch (error) {
        setDeployLogs(["Error during deployment: " + error]);
        return;
      }
    };
    deploy();
  }, []);
  return (
    <ProtectedRoute>
      <PageHeader>Deploy service</PageHeader>
      <Box>
        <VStack>
          {deployLogs.map((log) => (
            <span>{log}</span>
          ))}
        </VStack>
      </Box>
    </ProtectedRoute>
  );
}

export default DeployService;
