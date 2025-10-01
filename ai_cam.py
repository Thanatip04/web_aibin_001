import cv2
import numpy as np
import tensorflow as tf
from tensorflow.keras.models import Sequential, Model
from tensorflow.keras.layers import Dense, Dropout, GlobalAveragePooling2D, Input
from tensorflow.keras.applications import MobileNetV2 # <-- เราจะลองใช้ MobileNetV2 เป็นฐาน
import os

# ----------------- 1. กำหนดค่าและโหลดทรัพยากร -----------------

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
MODEL_PATH = os.path.join(BASE_DIR, 'AI-TF', 'keras_model.h5')
LABELS_PATH = os.path.join(BASE_DIR, 'AI-TF', 'labels.txt')

# **สำคัญ: แก้ไข INPUT_SIZE ให้ตรงกับขนาดที่โมเดล 'keras_model.h5' ต้องการ**
INPUT_SIZE = (224, 224) 

# โหลดรายชื่อคลาส
try:
    with open(LABELS_PATH, 'r', encoding='utf-8') as f:
        CLASS_NAMES = [line.strip() for line in f.readlines()]
    NUM_CLASSES = len(CLASS_NAMES) # จำนวนคลาส
except FileNotFoundError:
    print(f"Error: Label file not found at {LABELS_PATH}")
    CLASS_NAMES = ["Class 1", "Class 2"] 
    NUM_CLASSES = 2

# ----------------- 1.5 สร้างโครงสร้างโมเดลใหม่ (Model Reconstruction) -----------------

try:
    # 1. สร้าง Base Model (สมมติว่าเป็น MobileNetV2 ซึ่งเป็นโมเดลฐานยอดนิยม)
    # ต้องมั่นใจว่า INPUT_SIZE และ Input shape (None, 224, 224, 3) ถูกต้อง
    base_model = MobileNetV2(
        input_shape=(INPUT_SIZE[0], INPUT_SIZE[1], 3),
        include_top=False, # ไม่รวมส่วนหัวเดิม
        weights=None      # ไม่ใช้ weights ที่ pre-trained มาจาก ImageNet
    )

    # 2. สร้าง Classification Head ใหม่ที่อยู่ด้านบน
    x = base_model.output
    x = GlobalAveragePooling2D()(x) # ใช้ GlobalAveragePooling2D เหมือน Teachable Machine
    
    # ถ้าโมเดลของคุณมี Dropout 
    # x = Dropout(0.5)(x) 
    
    # Output Layer
    predictions = Dense(NUM_CLASSES, activation='softmax')(x) 

    # 3. รวม Base Model และ Head เข้าด้วยกัน
    model = Model(inputs=base_model.input, outputs=predictions)

    # 4. โหลดเฉพาะ Weights (น้ำหนัก) จากไฟล์ .h5
    # นี่คือขั้นตอนสำคัญที่จะข้ามปัญหา Functional API Error
    model.load_weights(MODEL_PATH, by_name=True) 
    
    print(f"Model structure reconstructed and weights loaded successfully.")
    print(f"Model Input Shape: {model.input_shape}")
    
except Exception as e:
    print(f"FATAL ERROR during model reconstruction: {e}")
    print(f"*** การแก้ไขโมเดลล้มเหลว! อาจต้องใช้โมเดลฐานอื่นที่ไม่ใช่ MobileNetV2 ***")
    exit()

# ----------------- 2. เริ่มต้นกล้องและการประมวลผล -----------------

cap = cv2.VideoCapture(0) # 0 คือกล้องหลักของระบบ

if not cap.isOpened():
    print("Error: Could not open webcam.")
    exit()

print("Webcam started. Press 'q' to exit.")

while True:
    ret, frame = cap.read()
    if not ret:
        break

   # 2.2 การประมวลผลล่วงหน้า (Pre-processing) 
    
    # 1. ปรับขนาดภาพให้ตรงกับ INPUT_SIZE
    resized_frame = cv2.resize(frame, INPUT_SIZE)

    # 2. แปลงเป็น NumPy Array และเพิ่มมิติ Batch
    input_array = np.expand_dims(resized_frame, axis=0)
    
    # 3. Normalization (Normalization 0 ถึง 1 ซึ่งเป็นที่นิยมที่สุด)
    normalized_array = input_array.astype('float32') / 255.0

    # 2.3 การทำนายผล (Inference)
    predictions = model.predict(normalized_array, verbose=0) 
    
    # ... โค้ดแสดงผลที่เหลือเหมือนเดิม ...
    
    # 2.4 แสดงผลลัพธ์
    predicted_class_index = np.argmax(predictions[0])
    confidence = np.max(predictions[0]) * 100
    
    if 0 <= predicted_class_index < len(CLASS_NAMES):
        label = CLASS_NAMES[predicted_class_index]
    else:
        label = "Unknown Class"

    predicted_text = f"Result: {label} ({confidence:.2f}%)"

    # แสดงข้อความบนเฟรม
    cv2.putText(frame, predicted_text, (10, 30), 
                cv2.FONT_HERSHEY_SIMPLEX, 0.8, (0, 255, 0), 2, cv2.LINE_AA)
    
    cv2.imshow('AI Camera Keras Prediction', frame)

    # 2.6 กด 'q' เพื่อออกจากโปรแกรม
    if cv2.waitKey(1) & 0xFF == ord('q'):
        break

# ----------------- 3. ทำความสะอาด -----------------
cap.release()
cv2.destroyAllWindows()
print("Program stopped and resources released.")