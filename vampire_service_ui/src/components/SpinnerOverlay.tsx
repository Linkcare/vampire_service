import { Flex, Portal, Spinner } from "@chakra-ui/react";

function SpinnerOverlay() {
  return (
    <Portal>
      <Flex
        position="fixed"
        top={0}
        left={0}
        width="100vw"
        height="100vh"
        bg="rgba(0, 0, 0, 0.4)"
        zIndex={9999}
        align="center"
        justify="center"
      >
        <Spinner size="xl" color="white" />
      </Flex>
    </Portal>
  );
}

export function LoadingOverlay({
  children,
  show = false,
}: {
  children: React.ReactNode;
  show?: boolean;
}) {
  return (
    <div style={{ position: "relative" }}>
      {children}
      {show && (
        <div
          style={{
            position: "absolute",
            top: 0,
            left: 0,
            right: 0,
            bottom: 0,
            backgroundColor: "rgba(0, 0, 0, 0.1)",
            zIndex: 10,
            pointerEvents: "auto",
          }}
        />
      )}
    </div>
  );
}
export default SpinnerOverlay;
