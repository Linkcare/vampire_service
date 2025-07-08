import { Center, Flex, Icon } from "@chakra-ui/react";
import { AiOutlineHome } from "react-icons/ai";

type PageHeaderProps = {
  children: React.ReactNode;
  showHomeLink?: boolean; // Optional prop to control the visibility of the home link
};

function PageHeader({ children, showHomeLink = true }: PageHeaderProps) {
  return (
    <Flex
      direction="row"
      style={{
        backgroundColor: "#215da1",
        color: "white",
        fontSize: "1.5rem",
        fontWeight: "bold",
        width: "100%",
        height: "50px",
      }}
    >
      <Center flex="1">{children}</Center>
      {showHomeLink && (
        <Center p="10px">
          <a href="/">
            <Icon size="lg">
              <AiOutlineHome />
            </Icon>
          </a>
        </Center>
      )}
    </Flex>
  );
}

export default PageHeader;
