/**
 * The map of an agent's call steps: drag a step to move it, drag from one to another to add an exit, click one to open it. Everything here can also be done from the list and forms beside it, which is
 * what a phone or a keyboard uses. This file alone loads the drawing library, and the page it sits on loads only when opened.
 *
 * Not here: what the steps hold and what is drawn for them (flowModel.ts), the forms (FlowStepForm.tsx).
 */
import { useEffect, useMemo } from "react";
import { Background, Controls, ReactFlow, ReactFlowProvider, useNodesState, useReactFlow, type Node } from "@xyflow/react";
import "@xyflow/react/dist/style.css";
import type { AgentFlowGraph } from "../../api";
import { toEdges, toNodes } from "./flowModel";

const FIT = { maxZoom: 1, padding: 0.15 };

interface CanvasProps {
  graph: AgentFlowGraph;
  selected: string | null;
  label: string;
  onSelect: (stepId: string | null) => void;
  onMove: (stepId: string, x: number, y: number) => void;
  onConnect: (from: string, to: string) => void;
}

export function FlowCanvas(props: CanvasProps) {
  return (
    <ReactFlowProvider>
      <Canvas {...props} />
    </ReactFlowProvider>
  );
}

function Canvas({ graph, selected, label, onSelect, onMove, onConnect }: CanvasProps) {
  const { fitView } = useReactFlow();
  const [nodes, setNodes, onNodesChange] = useNodesState<Node>(toNodes(graph, selected));
  useEffect(() => {
    setNodes((was) => toNodes(graph, selected).map((node) => {
      const old = was.find((n) => n.id === node.id);
      return old ? { ...old, ...node } : node;
    }));
  }, [graph, selected, setNodes]);
  const edges = useMemo(() => toEdges(graph), [graph]);
  // A new step can land outside what is showing, so the map follows the number of steps, and leaves alone a view someone has moved.
  const count = graph.steps.length;
  useEffect(() => {
    const frame = requestAnimationFrame(() => void fitView(FIT));
    return () => cancelAnimationFrame(frame);
  }, [count, fitView]);
  const sourceOf = (exitId: string) => graph.exits.find((e) => e.id === exitId)?.from ?? null;

  return (
    <div className="flow-canvas" role="group" aria-label={label}>
      <ReactFlow
        nodes={nodes}
        edges={edges}
        onNodesChange={onNodesChange}
        onNodeClick={(_, node) => onSelect(node.id)}
        onEdgeClick={(_, edge) => onSelect(sourceOf(edge.id))}
        onPaneClick={() => onSelect(null)}
        onNodeDragStop={(_, node) => onMove(node.id, node.position.x, node.position.y)}
        onConnect={(c) => { if (c.source && c.target) onConnect(c.source, c.target); }}
        deleteKeyCode={null}
        fitView
        fitViewOptions={FIT}
        minZoom={0.2}
      >
        <Background />
        <Controls showInteractive={false} />
      </ReactFlow>
    </div>
  );
}
